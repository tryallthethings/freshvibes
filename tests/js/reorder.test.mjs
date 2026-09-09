import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

/**
 * Behavioural tests for the dashboard's layout/reorder plumbing (FV-003, FV-006).
 *
 * Three defects live here, each one only visible when two things happen at once.
 *
 * A reorder replaces `state.layout` before the request completes. Restoring a captured snapshot on
 * failure repairs a single failed drag, but a delayed failure from an earlier drag must not undo a
 * later drag the server accepted, and two reorders must not be in flight at all: the server applies
 * them in arrival order, and category reordering is several row updates with no transaction.
 * Disabling the Sortable is not enough on its own, because a rerender destroys and recreates it and
 * the replacement starts from Sortable's enabled default.
 *
 * Auto-refresh replaced only the feed list. A subscription added elsewhere then existed in
 * `state.feeds` but in no tab of the client's older `state.layout`, and the renderer shows an
 * unclaimed feed in the first visible tab regardless, so a drag there submitted more feeds than the
 * stored tab holds and the save was rejected.
 *
 * The plumbing lives inside the dashboard closure and the repository has no browser test setup, so
 * the functions are lifted out of the source and executed against controlled promises. That is
 * weaker than a browser test - no DOM, no real Sortable - but it exercises the shipped function
 * bodies rather than a copy of them.
 */

const SOURCE = readFileSync(fileURLToPath(new URL('../../static/script.js', import.meta.url)), 'utf8');

/** Lift one top-level function out of the dashboard closure by brace matching. */
function extract(name) {
	const start = SOURCE.indexOf(`\n\tfunction ${name}(`);
	assert.notEqual(start, -1, `${name}() not found in static/script.js`);

	const open = SOURCE.indexOf('{', start);
	let depth = 0;
	for (let i = open; i < SOURCE.length; i++) {
		if (SOURCE[i] === '{') depth++;
		else if (SOURCE[i] === '}' && --depth === 0) {
			return SOURCE.slice(start, i + 1);
		}
	}
	throw new Error(`Unbalanced braces while extracting ${name}()`);
}

/** A promise plus the handles to settle it later. */
function deferred() {
	let resolve;
	let reject;
	const promise = new Promise((res, rej) => {
		resolve = res;
		reject = rej;
	});
	return { promise, resolve, reject };
}

function tabs(...specs) {
	return specs.map(spec => (typeof spec === 'string'
		? { id: spec, name: spec, columns: { col1: [] } }
		: spec));
}

function tabWith(id, feeds) {
	return { id, name: id, columns: { col1: feeds } };
}

function order(layout) {
	return layout.map(tab => tab.id).join(' ');
}

/** Let every already-resolved promise callback run. */
const settle = () => new Promise(resolve => setImmediate(resolve));

const OK = { status: 'success' };
const FAILED = { status: 'error', ok: false };

/**
 * Build a harness around the real functions.
 *
 * `isCategoryMode` selects the category-ordering endpoint, the mode with partial-write risk, so
 * both are exercised.
 */
function harness({ isCategoryMode = false, initial = tabs('A', 'B', 'C'), refreshHtml = '' } = {}) {
	const state = { layout: initial, feeds: {}, activeTabId: initial[0]?.id ?? null, allPlacedFeedIds: new Set() };
	const apiCalls = [];
	const fetchCalls = [];
	const errors = [];
	const renders = [];

	// Sortable doubles that can be replaced, which is what a rerender does to them.
	const makeSortable = disabled => ({ disabled, option(key, value) {
		if (key === 'disabled') this.disabled = value;
	} });
	const sortables = { vertical: makeSortable(false), tabs: makeSortable(false) };

	const api = (url, payload) => {
		const pending = deferred();
		apiCalls.push({ url, payload, ...pending });
		return pending.promise;
	};
	const fetchStub = (url, options) => {
		const pending = deferred();
		fetchCalls.push({ url, options, ...pending });
		return pending.promise;
	};

	// Minimal document/DOMParser for applyRefreshedDocument().
	const parsedDoc = {
		getElementById: id => (id === 'feeds-data-script' ? { textContent: refreshHtml } : null),
		querySelector: () => null,
	};
	const documentStub = { querySelector: () => null };
	class DOMParserStub {
		parseFromString() {
			return parsedDoc;
		}
	}

	const factory = new Function(
		'state', 'urls', 'api', 'isOk', 'fetch', 'assignUniqueSlugs', 'handleAPIError',
		'sortables', 'isCategoryMode', 'render', 'renderTabs', 'document', 'DOMParser',
		`
			let reorderSequence = 0;
			let reorderPending = false;
			let currentCsrfToken = 'token';
			let verticalLayoutSortable = sortables.vertical;
			const tabsContainer = { get sortable() { return sortables.tabs; } };
			${extract('applyReorderLock')}
			${extract('setReorderPending')}
			${extract('adoptLayout')}
			${extract('fetchLayout')}
			${extract('reloadAuthoritativeLayout')}
			${extract('persistTabOrder')}
			${extract('applyRefreshedDocument')}
			return {
				persistTabOrder,
				reloadAuthoritativeLayout,
				adoptLayout,
				applyRefreshedDocument,
				applyReorderLock,
				replaceVerticalSortable: make => { verticalLayoutSortable = make(reorderPending); },
				get sequence() { return reorderSequence; },
				get pending() { return reorderPending; },
			};
		`
	);

	const rerender = () => renders.push(order(state.layout));

	const lifted = factory(
		state,
		{ tabAction: '/tab', saveCategoryOrder: '/category', getLayout: '/layout' },
		api,
		data => !!data && data.ok !== false && data.status !== 'error',
		fetchStub,
		layout => layout,
		(context, error) => errors.push({ context, error }),
		sortables,
		isCategoryMode,
		rerender,
		rerender,
		documentStub,
		DOMParserStub
	);

	return {
		state, apiCalls, fetchCalls, errors, renders, sortables, lifted, makeSortable,
		reorder: newOrder => lifted.persistTabOrder(newOrder, 'Reorder tabs', rerender),
	};
}

for (const isCategoryMode of [false, true]) {
	const mode = isCategoryMode ? 'category mode' : 'custom mode';

	// ------------------------------------------------------------------ FV-006

	test(`${mode}: only one reorder is ever in flight`, async () => {
		const h = harness({ isCategoryMode });

		const first = h.reorder(['B', 'A', 'C']);
		assert.equal(order(h.state.layout), 'B A C');
		assert.equal(h.lifted.pending, true);

		// A rerender has installed a fresh Sortable, so the second drag reaches the helper.
		await h.reorder(['C', 'B', 'A']);

		assert.equal(h.apiCalls.length, 1, 'the second drag must not be sent');
		assert.equal(order(h.state.layout), 'B A C', 'the outstanding order stays on screen');

		h.apiCalls[0].resolve(OK);
		await first;
		await settle();

		assert.equal(order(h.state.layout), 'B A C');
		assert.equal(h.lifted.pending, false);
	});

	test(`${mode}: a Sortable created during a pending reorder starts disabled`, async () => {
		const h = harness({ isCategoryMode });

		const pending = h.reorder(['B', 'A', 'C']);
		assert.equal(h.sortables.vertical.disabled, true);
		assert.equal(h.sortables.tabs.disabled, true);

		// What renderVerticalLayout() does: destroy the instance and build a replacement, whose
		// `disabled` option is read from the pending flag.
		h.lifted.replaceVerticalSortable(disabled => {
			h.sortables.vertical = h.makeSortable(disabled);
			return h.sortables.vertical;
		});
		h.lifted.applyReorderLock();

		assert.equal(h.sortables.vertical.disabled, true, 'the replacement must inherit the lock');

		h.apiCalls[0].resolve(OK);
		await pending;
		await settle();

		assert.equal(h.sortables.vertical.disabled, false, 'and be released when the request settles');
	});

	test(`${mode}: the lock is released so a later drag still works`, async () => {
		const h = harness({ isCategoryMode });

		const first = h.reorder(['B', 'A', 'C']);
		h.apiCalls[0].resolve(OK);
		await first;
		await settle();

		const second = h.reorder(['C', 'B', 'A']);
		assert.equal(h.apiCalls.length, 2);
		h.apiCalls[1].resolve(OK);
		await second;

		assert.equal(order(h.state.layout), 'C B A');
	});

	test(`${mode}: a superseded failure callback leaves state alone`, async () => {
		const h = harness({ isCategoryMode });
		const before = order(h.state.layout);

		// A response belonging to an operation that is no longer the current one.
		const done = h.lifted.reloadAuthoritativeLayout(-1, tabs('Z'), () => {
			throw new Error('a superseded callback must not redraw');
		});
		await settle();

		assert.equal(h.fetchCalls.length, 1);
		h.fetchCalls[0].resolve({ ok: true, json: async () => ({ layout: tabs('Z') }) });
		await done;
		await settle();

		assert.equal(order(h.state.layout), before, 'the superseded result is discarded');
	});

	test(`${mode}: a single failure adopts the order the server actually holds`, async () => {
		const h = harness({ isCategoryMode });

		const pending = h.reorder(['B', 'A', 'C']);
		assert.equal(order(h.state.layout), 'B A C');

		h.apiCalls[0].resolve(FAILED);
		await settle();

		assert.equal(h.fetchCalls.length, 1, 'a rejected reorder must re-read authoritative state');
		assert.equal(h.fetchCalls[0].url, '/layout');

		// The server had applied part of the change before failing.
		h.fetchCalls[0].resolve({ ok: true, json: async () => ({ layout: tabs('A', 'C', 'B') }) });
		await pending;
		await settle();

		assert.equal(order(h.state.layout), 'A C B');
		assert.equal(h.errors.length, 1);
		assert.equal(h.lifted.pending, false);
	});

	test(`${mode}: an unreadable layout falls back to the order held before the drag`, async () => {
		const h = harness({ isCategoryMode });

		const pending = h.reorder(['B', 'A', 'C']);
		h.apiCalls[0].resolve(FAILED);
		await settle();

		h.fetchCalls[0].resolve({ ok: false, status: 500 });
		await pending;
		await settle();

		assert.equal(order(h.state.layout), 'A B C');
		assert.equal(h.errors.length, 2, 'both the reorder and the re-read failures are reported');
		assert.equal(h.lifted.pending, false);
	});

	test(`${mode}: a successful reorder keeps the new order and re-reads nothing`, async () => {
		const h = harness({ isCategoryMode });

		const pending = h.reorder(['C', 'A', 'B']);
		h.apiCalls[0].resolve(OK);
		await pending;
		await settle();

		assert.equal(order(h.state.layout), 'C A B');
		assert.equal(h.fetchCalls.length, 0);
		assert.equal(h.errors.length, 0);
		assert.equal(
			h.apiCalls[0].url,
			isCategoryMode ? '/category' : '/tab',
			'each mode uses its own endpoint'
		);
	});
}

// ---------------------------------------------------------------------- FV-003

test('adopting a layout records every placed feed', () => {
	const h = harness({ initial: tabs(tabWith('main', ['1', '2'])) });

	h.lifted.adoptLayout([tabWith('main', ['1', '2']), tabWith('overflow', ['3'])], 'main');

	assert.deepEqual([...h.state.allPlacedFeedIds].sort(), ['1', '2', '3']);
	assert.equal(h.state.activeTabId, 'main', 'a still-present active tab is kept');
});

test('adopting a layout that dropped the active tab falls back to the server choice', () => {
	const h = harness({ initial: tabs('gone') });

	h.lifted.adoptLayout(tabs('A', 'B'), 'B');

	assert.equal(h.state.activeTabId, 'B');
});

test('auto-refresh adopts the layout alongside the feeds', async () => {
	// One tab holding feeds 1 and 2; the refresh reports a third subscription.
	const h = harness({
		initial: tabs(tabWith('main', ['1', '2'])),
		refreshHtml: JSON.stringify({ 1: { id: 1 }, 2: { id: 2 }, 3: { id: 3 } }),
	});
	h.lifted.adoptLayout([tabWith('main', ['1', '2'])], 'main');

	const applied = h.lifted.applyRefreshedDocument('<html></html>');
	await settle();

	assert.equal(h.fetchCalls.length, 1, 'the refresh must re-read the layout');
	assert.equal(h.fetchCalls[0].url, '/layout');

	// The server has reconciled feed 3 into an overflow tab.
	h.fetchCalls[0].resolve({
		ok: true,
		json: async () => ({ layout: [tabWith('main', ['1', '2']), tabWith('overflow', ['3'])], active_tab_id: 'main' }),
	});
	await applied;
	await settle();

	assert.equal(order(h.state.layout), 'main overflow');
	assert.ok(h.state.allPlacedFeedIds.has('3'), 'the new feed must no longer count as unplaced');
	assert.equal(Object.keys(h.state.feeds).length, 3);
});

test('auto-refresh keeps the refreshed articles when the layout cannot be re-read', async () => {
	const h = harness({
		initial: tabs(tabWith('main', ['1'])),
		refreshHtml: JSON.stringify({ 1: { id: 1 }, 2: { id: 2 } }),
	});

	const applied = h.lifted.applyRefreshedDocument('<html></html>');
	await settle();

	h.fetchCalls[0].resolve({ ok: false, status: 503 });
	await applied;
	await settle();

	assert.equal(Object.keys(h.state.feeds).length, 2, 'articles are still updated');
	assert.equal(h.errors.length, 1);
	assert.ok(h.renders.length > 0, 'the dashboard is still redrawn');
});

test('auto-refresh does not overwrite a reorder the server has not confirmed', async () => {
	const h = harness({
		initial: tabs('A', 'B', 'C'),
		refreshHtml: JSON.stringify({ 1: { id: 1 } }),
	});

	const reordering = h.reorder(['B', 'A', 'C']);
	const applied = h.lifted.applyRefreshedDocument('<html></html>');
	await settle();

	// The refresh read is in flight alongside the reorder; its result must not be adopted.
	const layoutRead = h.fetchCalls.find(call => call.url === '/layout');
	layoutRead.resolve({ ok: true, json: async () => ({ layout: tabs('C', 'B', 'A'), active_tab_id: 'C' }) });
	await applied;
	await settle();

	assert.equal(order(h.state.layout), 'B A C', 'the pending order survives the refresh');

	h.apiCalls[0].resolve(OK);
	await reordering;
});
