import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

/**
 * Behavioural tests for tab reordering (FV-006).
 *
 * Both reorder handlers replace `state.layout` before the request completes. Restoring a captured
 * snapshot on failure fixes a single failed drag, but the callback used to write that snapshot
 * back unconditionally: a delayed failure from an earlier drag would undo a later drag the server
 * had already accepted. Category reordering makes that worse, because it applies one row update at
 * a time and can stop halfway, so the client's snapshot is not necessarily what is stored either.
 *
 * The reorder plumbing lives inside the dashboard closure and the repository has no browser test
 * setup, so the functions are lifted out of the source and executed against controlled promises.
 * That is weaker than a browser test - no DOM, no real Sortable - but it does exercise the actual
 * shipped function bodies rather than a copy of them.
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

function tabs(...ids) {
	return ids.map(id => ({ id, name: id, columns: { col1: [] } }));
}

function order(layout) {
	return layout.map(tab => tab.id).join(' ');
}

/**
 * Build a harness around the real reorder functions.
 *
 * `isCategoryMode` selects the category-ordering endpoint, which is the mode with partial-write
 * risk, so both are exercised.
 */
function harness({ isCategoryMode = false, initial = tabs('A', 'B', 'C') } = {}) {
	const state = { layout: initial, feeds: {}, activeTabId: null, allPlacedFeedIds: new Set() };
	const apiCalls = [];
	const fetchCalls = [];
	const errors = [];
	const renders = [];
	const sortableState = { vertical: false, tabs: false };

	const verticalLayoutSortable = {
		option: (key, value) => {
			if (key === 'disabled') sortableState.vertical = value;
		},
	};
	const tabsContainer = { sortable: { option: (key, value) => {
		if (key === 'disabled') sortableState.tabs = value;
	} } };

	const api = (url, payload) => {
		const pending = deferred();
		apiCalls.push({ url, payload, ...pending });
		return pending.promise;
	};
	const fetchStub = url => {
		const pending = deferred();
		fetchCalls.push({ url, ...pending });
		return pending.promise;
	};

	const factory = new Function(
		'state', 'urls', 'api', 'isOk', 'fetch', 'assignUniqueSlugs', 'handleAPIError',
		'verticalLayoutSortable', 'tabsContainer', 'isCategoryMode', 'rerender',
		`
			let reorderSequence = 0;
			${extract('setReorderEnabled')}
			${extract('reloadAuthoritativeLayout')}
			${extract('persistTabOrder')}
			return {
				persistTabOrder,
				get sequence() { return reorderSequence; },
			};
		`
	);

	const api2 = factory(
		state,
		{ tabAction: '/tab', saveCategoryOrder: '/category', getLayout: '/layout' },
		api,
		data => !!data && data.ok !== false && data.status !== 'error',
		fetchStub,
		layout => layout,
		(context, error) => errors.push({ context, error }),
		verticalLayoutSortable,
		tabsContainer,
		isCategoryMode,
		() => renders.push(order(state.layout))
	);

	return {
		state, apiCalls, fetchCalls, errors, renders, sortableState,
		reorder: newOrder => api2.persistTabOrder(newOrder, 'Reorder tabs', () => renders.push(order(state.layout))),
	};
}

const OK = { status: 'success' };
const FAILED = { status: 'error', ok: false };

/** Let every already-resolved promise callback run. */
const settle = () => new Promise(resolve => setImmediate(resolve));

for (const isCategoryMode of [false, true]) {
	const mode = isCategoryMode ? 'category mode' : 'custom mode';

	test(`${mode}: a stale failure does not undo a newer accepted order`, async () => {
		const h = harness({ isCategoryMode });
		assert.equal(order(h.state.layout), 'A B C');

		const first = h.reorder(['B', 'A', 'C']);
		assert.equal(order(h.state.layout), 'B A C');

		// A second drag is sent before the first response arrives.
		const second = h.reorder(['C', 'B', 'A']);
		assert.equal(order(h.state.layout), 'C B A');

		h.apiCalls[1].resolve(OK);
		await second;

		// The first request now fails, long after the server accepted the second order.
		h.apiCalls[0].resolve(FAILED);
		await first;
		await settle();

		assert.equal(order(h.state.layout), 'C B A', 'the accepted order must survive');
		assert.equal(h.fetchCalls.length, 0, 'a superseded failure must not re-read the layout');
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
	});

	test(`${mode}: dragging is blocked while a reorder is outstanding`, async () => {
		const h = harness({ isCategoryMode });

		const pending = h.reorder(['B', 'A', 'C']);
		assert.equal(h.sortableState.vertical, true, 'vertical sortable disabled while pending');
		assert.equal(h.sortableState.tabs, true, 'tab sortable disabled while pending');

		h.apiCalls[0].resolve(OK);
		await pending;

		assert.equal(h.sortableState.vertical, false, 'vertical sortable re-enabled');
		assert.equal(h.sortableState.tabs, false, 'tab sortable re-enabled');
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
