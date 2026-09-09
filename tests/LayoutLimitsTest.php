<?php

declare(strict_types=1);

namespace tryallthethings\FreshVibes\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use tryallthethings\FreshVibes\Models\LayoutSchema;

/**
 * Regression tests for the structural ceilings (FV-003).
 *
 * The ceilings used to be absolute, which made them reject layouts the extension had generated
 * itself: one tab per category above `MAX_TABS`, or every subscription in a single tab above
 * `MAX_FEEDS_PER_TAB`. Such an account could not initialise or repair its dashboard, because the
 * only state the UI could offer was the state the write path refused.
 *
 * They are now enforced against growth: a stored layout that is already over a ceiling may be kept
 * or reduced, never pushed further.
 */
#[CoversClass(LayoutSchema::class)]
final class LayoutLimitsTest extends TestCase {

	/**
	 * Build a layout of `$tabCount` tabs, each holding `$feedsPerTab` distinct feeds.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function layout(int $tabCount, int $feedsPerTab): array {
		$layout = [];
		$nextFeedId = 1;
		for ($t = 0; $t < $tabCount; $t++) {
			$feeds = [];
			for ($f = 0; $f < $feedsPerTab; $f++) {
				$feeds[] = $nextFeedId++;
			}
			$layout[] = [
				'id' => 'tab-' . $t,
				'name' => 'Tab ' . $t,
				'num_columns' => 1,
				'columns' => ['col1' => $feeds],
			];
		}
		return $layout;
	}

	// ---------------------------------------------------------------- absolute ceilings

	public function testALayoutAtTheCeilingsIsAccepted(): void {
		self::assertTrue(LayoutSchema::withinLimits(self::layout(LayoutSchema::MAX_TABS, 1)));
		self::assertTrue(LayoutSchema::withinLimits(self::layout(1, LayoutSchema::MAX_FEEDS_PER_TAB)));
	}

	public function testALayoutOverACeilingIsRejectedWithoutABaseline(): void {
		self::assertFalse(LayoutSchema::withinLimits(self::layout(LayoutSchema::MAX_TABS + 1, 1)));
		self::assertFalse(LayoutSchema::withinLimits(self::layout(1, LayoutSchema::MAX_FEEDS_PER_TAB + 1)));
	}

	// ---------------------------------------------------------------- growth-relative ceilings

	public function testAnAlreadyOversizedTabCountMayBeKept(): void {
		$stored = self::layout(LayoutSchema::MAX_TABS + 50, 1);
		self::assertTrue(LayoutSchema::withinLimits($stored, $stored));
	}

	public function testAnAlreadyOversizedTabCountMayBeReduced(): void {
		$stored = self::layout(LayoutSchema::MAX_TABS + 50, 1);
		$smaller = self::layout(LayoutSchema::MAX_TABS + 10, 1);
		self::assertTrue(LayoutSchema::withinLimits($smaller, $stored));
	}

	public function testAnAlreadyOversizedTabCountMayNotGrowFurther(): void {
		$stored = self::layout(LayoutSchema::MAX_TABS + 50, 1);
		$bigger = self::layout(LayoutSchema::MAX_TABS + 51, 1);
		self::assertFalse(LayoutSchema::withinLimits($bigger, $stored));
	}

	public function testAnAlreadyOversizedTabMayBeKeptButNotGrown(): void {
		$stored = self::layout(1, LayoutSchema::MAX_FEEDS_PER_TAB + 20);
		self::assertTrue(LayoutSchema::withinLimits($stored, $stored));
		self::assertTrue(LayoutSchema::withinLimits(self::layout(1, LayoutSchema::MAX_FEEDS_PER_TAB + 5), $stored));
		self::assertFalse(LayoutSchema::withinLimits(self::layout(1, LayoutSchema::MAX_FEEDS_PER_TAB + 21), $stored));
	}

	public function testAnEmptyBaselineDoesNotRaiseTheCeilings(): void {
		self::assertFalse(LayoutSchema::withinLimits(self::layout(LayoutSchema::MAX_TABS + 1, 1), []));
	}

	// ---------------------------------------------------------------- size helpers

	public function testLargestTabSizeReportsTheFullestTab(): void {
		$layout = [
			['id' => 'a', 'columns' => ['col1' => [1, 2], 'col2' => [3]]],
			['id' => 'b', 'columns' => ['col1' => [4]]],
		];
		self::assertSame(3, LayoutSchema::largestTabSize($layout));
		self::assertSame(0, LayoutSchema::largestTabSize([]));
	}

	public function testTabSizeCountsOnlyTheNamedTab(): void {
		$layout = [
			['id' => 'a', 'columns' => ['col1' => [1, 2], 'col2' => [3]]],
			['id' => 'b', 'columns' => ['col1' => [4]]],
		];
		self::assertSame(3, LayoutSchema::tabSize($layout, 'a'));
		self::assertSame(1, LayoutSchema::tabSize($layout, 'b'));
		self::assertSame(0, LayoutSchema::tabSize($layout, 'missing'));
	}

	// ---------------------------------------------------------------- per-tab drag ceiling

	public function testValidateColumnsStillRejectsGrowthPastTheCeiling(): void {
		$known = range(1, LayoutSchema::MAX_FEEDS_PER_TAB + 1);
		$submitted = ['col1' => $known];
		self::assertNull(LayoutSchema::validateColumns($submitted, $known, 1));
	}

	public function testValidateColumnsAcceptsAnOversizedTabAtItsCurrentSize(): void {
		$size = LayoutSchema::MAX_FEEDS_PER_TAB + 25;
		$known = range(1, $size);
		$columns = LayoutSchema::validateColumns(['col1' => $known], $known, 1, $size);
		self::assertNotNull($columns);
		self::assertCount($size, $columns['col1']);
	}

	public function testTheCurrentSizeCanOnlyRaiseTheCeilingNeverLowerIt(): void {
		$known = range(1, 10);
		$columns = LayoutSchema::validateColumns(['col1' => $known], $known, 1, 2);
		self::assertNotNull($columns);
		self::assertCount(10, $columns['col1']);
	}
}
