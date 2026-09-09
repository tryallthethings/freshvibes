<?php

declare(strict_types=1);

namespace tryallthethings\FreshVibes\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tryallthethings\FreshVibes\Models\LayoutSchema;

/**
 * Regression tests for placing newly subscribed feeds (FV-003).
 *
 * A custom-mode layout used to leave a new subscription out of every tab's `columns`. The client
 * renders an unplaced feed into the first tab regardless, so the displayed arrangement held one
 * more feed than the stored tab was allowed to hold, and the next drag inside that tab was
 * rejected. The chunking added for first-run layouts never ran on this path, because a layout
 * already existed.
 *
 * What these lock in is the invariant behind the fix: after reconciliation, every subscribed feed
 * is placed somewhere, so what the dashboard displays is what a drag can save.
 */
#[CoversClass(LayoutSchema::class)]
final class FeedPlacementTest extends TestCase {

	/**
	 * @param list<int> $feeds
	 * @return array<string,mixed>
	 */
	private static function tab(string $id, array $feeds = [], int $columns = 3): array {
		$layout = LayoutSchema::buildEmptyColumns($columns);
		foreach ($feeds as $i => $feedId) {
			$layout['col' . (($i % $columns) + 1)][] = $feedId;
		}
		return ['id' => $id, 'name' => $id, 'num_columns' => $columns, 'columns' => $layout];
	}

	/** @return callable(int, list<array<string,mixed>>): array<string,mixed> */
	private static function tabFactory(): callable {
		return static function (int $index, array $soFar): array {
			$id = 'overflow-' . $index;
			// Mirrors the controller's factory, which is handed the layout so far so a generated
			// ID cannot collide with one that is already in it.
			self::assertFalse(LayoutSchema::tabExists($soFar, $id));
			return self::tab($id);
		};
	}

	// ---------------------------------------------------------------- placeFeeds

	public function testFeedsAreAppendedAcrossTheTabsColumns(): void {
		$layout = [self::tab('a', [1, 2, 3])];

		self::assertSame([], LayoutSchema::placeFeeds($layout, [4, 5], 'bottom'));
		self::assertSame(
			['col1' => [1, 4], 'col2' => [2, 5], 'col3' => [3]],
			$layout[0]['columns']
		);
	}

	public function testTheTopPreferencePrependsToTheFirstColumn(): void {
		$layout = [self::tab('a', [1, 2, 3])];

		self::assertSame([], LayoutSchema::placeFeeds($layout, [4, 5], 'top'));
		self::assertSame([4, 5, 1], $layout[0]['columns']['col1']);
	}

	public function testOnlyTheFirstTabWithCapacityIsFilled(): void {
		$layout = [self::tab('a'), self::tab('b')];

		LayoutSchema::placeFeeds($layout, [1, 2], 'bottom');

		self::assertSame([1, 2], LayoutSchema::placedFeedIds([$layout[0]]));
		self::assertSame([], LayoutSchema::placedFeedIds([$layout[1]]));
	}

	public function testAFullTabIsSkippedAndTheNextOneUsed(): void {
		$layout = [
			self::tab('full', range(1, LayoutSchema::MAX_FEEDS_PER_TAB)),
			self::tab('spare'),
		];

		self::assertSame([], LayoutSchema::placeFeeds($layout, [90001], 'bottom'));
		self::assertSame(LayoutSchema::MAX_FEEDS_PER_TAB, LayoutSchema::tabSize($layout, 'full'));
		self::assertSame([90001], LayoutSchema::placedFeedIds([$layout[1]]));
	}

	public function testFeedsThatDoNotFitAreReportedBack(): void {
		$layout = [self::tab('full', range(1, LayoutSchema::MAX_FEEDS_PER_TAB))];

		self::assertSame([90001, 90002], LayoutSchema::placeFeeds($layout, [90001, 90002], 'bottom'));
		self::assertSame(LayoutSchema::MAX_FEEDS_PER_TAB, LayoutSchema::tabSize($layout, 'full'));
	}

	public function testMissingAndMalformedColumnsAreRepairedNotLost(): void {
		$layout = [['id' => 'a', 'name' => 'a', 'num_columns' => 3, 'columns' => ['col1' => 'not-an-array']]];

		self::assertSame([], LayoutSchema::placeFeeds($layout, [1, 2, 3], 'bottom'));
		self::assertSame([1, 2, 3], LayoutSchema::placedFeedIds($layout));
		self::assertSame(['col1', 'col2', 'col3'], array_keys($layout[0]['columns']));
	}

	// ---------------------------------------------------------------- reconcileFeeds

	/**
	 * The reported scenario: a stored tab at the per-tab ceiling, then one more subscription. The
	 * feed has to end up in the stored layout, because the dashboard displays it either way.
	 */
	public function testANewSubscriptionOnAFullTabGetsAnOverflowTab(): void {
		$layout = [self::tab('full', range(1, LayoutSchema::MAX_FEEDS_PER_TAB))];

		$result = LayoutSchema::reconcileFeeds($layout, [90001], 'bottom', self::tabFactory());

		self::assertCount(2, $result);
		self::assertSame(LayoutSchema::MAX_FEEDS_PER_TAB, LayoutSchema::tabSize($result, 'full'));
		self::assertContains(90001, LayoutSchema::placedFeedIds($result));
		self::assertTrue(LayoutSchema::withinLimits($result, $layout));
	}

	/** @return array<string,array{string}> */
	public static function positionProvider(): array {
		return ['bottom' => ['bottom'], 'top' => ['top']];
	}

	#[DataProvider('positionProvider')]
	public function testEverySubscriptionIsPlacedWhateverThePreference(string $position): void {
		$layout = [self::tab('full', range(1, LayoutSchema::MAX_FEEDS_PER_TAB))];
		$newFeeds = range(90001, 90005);

		$result = LayoutSchema::reconcileFeeds($layout, $newFeeds, $position, self::tabFactory());

		$placed = LayoutSchema::placedFeedIds($result);
		foreach ($newFeeds as $feedId) {
			self::assertContains($feedId, $placed);
		}
		self::assertCount(LayoutSchema::MAX_FEEDS_PER_TAB + count($newFeeds), $placed);
	}

	public function testManyNewSubscriptionsSpreadOverSeveralOverflowTabs(): void {
		$layout = [self::tab('full', range(1, LayoutSchema::MAX_FEEDS_PER_TAB))];
		$newFeeds = range(90001, 90000 + (LayoutSchema::MAX_FEEDS_PER_TAB * 2));

		$result = LayoutSchema::reconcileFeeds($layout, $newFeeds, 'bottom', self::tabFactory());

		self::assertCount(3, $result);
		self::assertCount(LayoutSchema::MAX_FEEDS_PER_TAB * 3, LayoutSchema::placedFeedIds($result));
		self::assertSame(LayoutSchema::MAX_FEEDS_PER_TAB, LayoutSchema::largestTabSize($result));
	}

	/**
	 * At the tab ceiling there is nowhere left to put an overflow tab. Placing the remainder past
	 * the per-tab limit keeps the stored layout in agreement with what is displayed, which is what
	 * lets the user move those feeds; leaving them unplaced is what broke dragging in the first
	 * place. The growth-relative write check then still accepts the result.
	 */
	public function testAtTheTabCeilingFeedsArePlacedRatherThanLeftUnreachable(): void {
		$layout = [];
		for ($i = 0; $i < LayoutSchema::MAX_TABS; $i++) {
			$layout[] = self::tab('tab-' . $i, [$i + 1]);
		}

		$result = LayoutSchema::reconcileFeeds($layout, [90001, 90002], 'bottom', self::tabFactory());

		self::assertCount(LayoutSchema::MAX_TABS, $result);
		self::assertContains(90001, LayoutSchema::placedFeedIds($result));
		self::assertContains(90002, LayoutSchema::placedFeedIds($result));
		self::assertTrue(LayoutSchema::withinLimits($result, $layout));
	}

	public function testReconcilingNothingChangesNothing(): void {
		$layout = [self::tab('a', [1, 2, 3])];

		self::assertSame($layout, LayoutSchema::reconcileFeeds($layout, [], 'bottom', self::tabFactory()));
	}

	/**
	 * The invariant the fix rests on: after reconciliation the displayed set and the stored set are
	 * the same, so the payload a within-tab drag submits is one the write path accepts.
	 */
	public function testAfterReconciliationADragOfTheFullTabValidates(): void {
		$subscribed = range(1, LayoutSchema::MAX_FEEDS_PER_TAB + 1);
		$stored = [self::tab('full', range(1, LayoutSchema::MAX_FEEDS_PER_TAB), 1)];

		$unplaced = array_values(array_diff($subscribed, LayoutSchema::placedFeedIds($stored)));
		$reconciled = LayoutSchema::reconcileFeeds($stored, $unplaced, 'bottom', self::tabFactory());

		// What the client serialises for the tab it is dragging in.
		foreach ($reconciled as $tab) {
			$columns = LayoutSchema::validateColumns(
				$tab['columns'],
				$subscribed,
				count($tab['columns']),
				LayoutSchema::tabSize($reconciled, $tab['id'])
			);
			self::assertNotNull($columns, 'A drag of tab ' . $tab['id'] . ' must be acceptable.');
		}
	}
}
