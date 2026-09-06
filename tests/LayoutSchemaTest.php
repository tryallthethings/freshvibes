<?php

declare(strict_types=1);

namespace tryallthethings\FreshVibes\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tryallthethings\FreshVibes\Models\LayoutSchema;

/**
 * Regression tests for the persisted-layout schema (FV-SEC-04 / FV-HARD-02).
 *
 * These are reliability boundaries rather than a cross-user security boundary: every caller is
 * behind POST + access + CSRF. What they prevent is a malformed or partial same-user request
 * corrupting or truncating a saved dashboard, which is what the original review reproduced.
 */
#[CoversClass(LayoutSchema::class)]
final class LayoutSchemaTest extends TestCase {

	private const SUBSCRIBED = [1, 2, 3];

	// ---------------------------------------------------------------- validateColumns

	public function testValidPayloadIsNormalisedAndPaddedToTheColumnCount(): void {
		self::assertSame(
			['col1' => [1, 2], 'col2' => [3], 'col3' => []],
			LayoutSchema::validateColumns(['col1' => [1, 2], 'col2' => [3]], self::SUBSCRIBED, 3)
		);
	}

	public function testNumericStringIdsAreCoercedToIntegers(): void {
		self::assertSame(
			['col1' => [1, 2], 'col2' => []],
			LayoutSchema::validateColumns(['col1' => ['1', '2']], self::SUBSCRIBED, 2)
		);
	}

	public function testUnsubscribedFeedsAreDropped(): void {
		self::assertSame(
			['col1' => [1]],
			LayoutSchema::validateColumns(['col1' => [1, 999]], self::SUBSCRIBED, 1)
		);
	}

	public function testDuplicatesAreDroppedWithinAndAcrossColumns(): void {
		self::assertSame(
			['col1' => [1, 2]],
			LayoutSchema::validateColumns(['col1' => [1, 1, 2]], self::SUBSCRIBED, 1)
		);
		self::assertSame(
			['col1' => [1], 'col2' => []],
			LayoutSchema::validateColumns(['col1' => [1], 'col2' => [1]], self::SUBSCRIBED, 2)
		);
	}

	public function testFeedsForARemovedColumnFallBackToTheFirstColumn(): void {
		self::assertSame(
			['col1' => [1], 'col2' => []],
			LayoutSchema::validateColumns(['col9' => [1]], self::SUBSCRIBED, 2)
		);
	}

	/**
	 * @return array<string,array{mixed}>
	 */
	public static function invalidColumnPayloadProvider(): array {
		return [
			'not an array' => ['nope'],
			'null' => [null],
			'integer' => [5],
			'unknown column key' => [['evil' => [1]]],
			'numeric column key' => [[0 => [1]]],
			'column key with path' => [['col1/../x' => [1]]],
			'scalar column value' => [['col1' => 'x']],
			'nested array value' => [['col1' => [[1]]]],
			'object value' => [['col1' => [['id' => 1]]]],
			'boolean feed id' => [['col1' => [true]]],
			'float feed id' => [['col1' => [1.5]]],
			'non numeric string id' => [['col1' => ['abc']]],
		];
	}

	#[DataProvider('invalidColumnPayloadProvider')]
	public function testInvalidPayloadsAreRejected(mixed $payload): void {
		self::assertNull(LayoutSchema::validateColumns($payload, self::SUBSCRIBED, 3));
	}

	public function testPerTabFeedCeilingIsEnforced(): void {
		$many = range(1, LayoutSchema::MAX_FEEDS_PER_TAB + 1);
		self::assertNull(LayoutSchema::validateColumns(['col1' => $many], $many, 1));
	}

	// ---------------------------------------------------------------- reorderTabs

	/** @return list<array<string,mixed>> */
	private static function layout(string ...$ids): array {
		return array_map(static fn(string $id): array => ['id' => $id, 'columns' => []], $ids);
	}

	public function testExactPermutationIsAccepted(): void {
		$result = LayoutSchema::reorderTabs(self::layout('a', 'b', 'c'), ['c', 'a', 'b']);

		self::assertNotNull($result);
		self::assertSame(['c', 'a', 'b'], array_column($result, 'id'));
	}

	/**
	 * @return array<string,array{list<string>}>
	 */
	public static function invalidReorderProvider(): array {
		return [
			// The reported data-loss bug: explode(',', '') yields [''], which passed the old
			// !empty() check and saved an empty layout, deleting every tab.
			'empty string' => [['']],
			'empty list' => [[]],
			'subset' => [['a', 'b']],
			'superset' => [['a', 'b', 'c', 'd']],
			'duplicate' => [['a', 'a', 'b']],
			'unknown id' => [['a', 'b', 'zz']],
			'all empty strings' => [['', '', '']],
		];
	}

	#[DataProvider('invalidReorderProvider')]
	public function testInvalidReordersAreRejected(array $requested): void {
		self::assertNull(LayoutSchema::reorderTabs(self::layout('a', 'b', 'c'), $requested));
	}

	// ---------------------------------------------------------------- category ordering

	public function testExactCategoryPermutationIsAccepted(): void {
		self::assertSame(
			[3, 1, 2],
			LayoutSchema::orderedCategoryIds(['cat-3', 'cat-1', 'cat-2'], [1, 2, 3])
		);
	}

	/**
	 * @return array<string,array{list<string>,list<int>}>
	 */
	public static function invalidCategoryOrderProvider(): array {
		return [
			// Previously produced [''], matched no category, updated nothing, returned success.
			'empty string' => [[''], [1, 2]],
			'subset leaves stale positions' => [['cat-1'], [1, 2]],
			'duplicate' => [['cat-1', 'cat-1'], [1, 2]],
			'unknown category' => [['cat-1', 'cat-99'], [1, 2]],
			'malformed prefix' => [['category-1', 'cat-2'], [1, 2]],
			'non numeric id' => [['cat-abc'], [1]],
			'zero id' => [['cat-0'], [1]],
			'negative id' => [['cat--1'], [1]],
			'no categories exist' => [['cat-1'], []],
		];
	}

	#[DataProvider('invalidCategoryOrderProvider')]
	public function testInvalidCategoryOrdersAreRejected(array $requested, array $existing): void {
		self::assertNull(LayoutSchema::orderedCategoryIds($requested, $existing));
	}

	public function testCategoryIdsTolerateSurroundingWhitespace(): void {
		self::assertSame([1, 2], LayoutSchema::orderedCategoryIds([' cat-1 ', 'cat-2'], [1, 2]));
	}

	// ---------------------------------------------------------------- structural limits

	public function testLayoutWithinLimitsIsAccepted(): void {
		self::assertTrue(LayoutSchema::withinLimits(self::layout('a', 'b')));
	}

	public function testTooManyTabsIsRejected(): void {
		$ids = array_map(static fn(int $i): string => "tab$i", range(1, LayoutSchema::MAX_TABS + 1));
		self::assertFalse(LayoutSchema::withinLimits(self::layout(...$ids)));
	}

	public function testTabExceedingThePerTabFeedCeilingIsRejected(): void {
		// Reached through moves and redistribution, which build layouts without validateColumns().
		$layout = [[
			'id' => 'a',
			'columns' => ['col1' => range(1, LayoutSchema::MAX_FEEDS_PER_TAB + 1)],
		]];
		self::assertFalse(LayoutSchema::withinLimits($layout));
	}

	public function testFeedsSpreadAcrossColumnsCountTowardsTheSameTabCeiling(): void {
		$half = intdiv(LayoutSchema::MAX_FEEDS_PER_TAB, 2) + 1;
		$layout = [[
			'id' => 'a',
			'columns' => ['col1' => range(1, $half), 'col2' => range($half + 1, $half * 2)],
		]];
		self::assertFalse(LayoutSchema::withinLimits($layout));
	}

	public function testTotalPlacedFeedsCountsEveryColumnOfEveryTab(): void {
		$layout = [
			['id' => 'a', 'columns' => ['col1' => [1, 2], 'col2' => [3]]],
			['id' => 'b', 'columns' => ['col1' => [4]]],
		];
		self::assertSame(4, LayoutSchema::totalPlacedFeeds($layout));
	}

	public function testTotalPlacedFeedsToleratesMissingOrMalformedColumns(): void {
		self::assertSame(0, LayoutSchema::totalPlacedFeeds([['id' => 'a']]));
		self::assertSame(0, LayoutSchema::totalPlacedFeeds([['id' => 'a', 'columns' => []]]));
	}

	// ---------------------------------------------------------------- misc helpers

	public function testTabExists(): void {
		$layout = self::layout('a', 'b');
		self::assertTrue(LayoutSchema::tabExists($layout, 'b'));
		self::assertFalse(LayoutSchema::tabExists($layout, 'zz'));
		self::assertFalse(LayoutSchema::tabExists($layout, ''));
	}

	public function testRedistributeColumnsSpreadsFeedsRoundRobin(): void {
		self::assertSame(
			['col1' => [1, 3], 'col2' => [2]],
			LayoutSchema::redistributeColumns(['columns' => ['col1' => [1, 2, 3]]], 2)
		);
	}

	public function testRedistributeColumnsHandlesATabWithNoColumnsKey(): void {
		self::assertSame(['col1' => [], 'col2' => []], LayoutSchema::redistributeColumns([], 2));
	}

	public function testColumnCountIsClampedToTheSupportedRange(): void {
		self::assertCount(LayoutSchema::MAX_COLUMNS, LayoutSchema::buildEmptyColumns(99));
		self::assertCount(LayoutSchema::MIN_COLUMNS, LayoutSchema::buildEmptyColumns(0));
		self::assertCount(LayoutSchema::MIN_COLUMNS, LayoutSchema::buildEmptyColumns(-5));
	}

	public function testDeduplicateKeepsOnlyTheFirstOccurrenceAcrossTabs(): void {
		$layout = [
			['id' => 'a', 'columns' => ['col1' => [1, 2]]],
			['id' => 'b', 'columns' => ['col1' => [2, 3]]],
		];
		LayoutSchema::deduplicate($layout);

		self::assertSame([1, 2], $layout[0]['columns']['col1']);
		self::assertSame([3], $layout[1]['columns']['col1']);
	}

	public function testDeduplicateTreatsIntAndStringIdsAsTheSameFeed(): void {
		// Layouts saved by older versions stored feed IDs as strings; mixing must not duplicate.
		$layout = [
			['id' => 'a', 'columns' => ['col1' => ['1'], 'col2' => [1]]],
		];
		LayoutSchema::deduplicate($layout);

		self::assertSame(['1'], $layout[0]['columns']['col1']);
		self::assertSame([], $layout[0]['columns']['col2']);
	}

	public function testDeduplicateReplacesMalformedColumnsWithEmptyArrays(): void {
		$layout = [['id' => 'a', 'columns' => ['col1' => 'not-an-array']]];
		LayoutSchema::deduplicate($layout);

		self::assertSame([], $layout[0]['columns']['col1']);
	}

	/**
	 * @return array<string,array{string,?string}>
	 */
	public static function nameProvider(): array {
		return [
			'trimmed' => ['  Hi  ', 'Hi'],
			'inner whitespace kept' => ['My Tab', 'My Tab'],
			'unicode counted by characters' => [str_repeat('é', LayoutSchema::MAX_TAB_NAME_LENGTH), str_repeat('é', LayoutSchema::MAX_TAB_NAME_LENGTH)],
			'empty' => ['', null],
			'whitespace only' => ['   ', null],
			'too long' => [str_repeat('x', LayoutSchema::MAX_TAB_NAME_LENGTH + 1), null],
			'too long unicode' => [str_repeat('é', LayoutSchema::MAX_TAB_NAME_LENGTH + 1), null],
		];
	}

	#[DataProvider('nameProvider')]
	public function testNormalizeName(string $input, ?string $expected): void {
		self::assertSame($expected, LayoutSchema::normalizeName($input));
	}

	public function testPlacedFeedIdsCollectsAcrossTabsWithoutDuplicates(): void {
		$layout = [
			['id' => 'a', 'columns' => ['col1' => [1, 2]]],
			['id' => 'b', 'columns' => ['col1' => ['2', 3]]],
		];
		$ids = LayoutSchema::placedFeedIds($layout);
		sort($ids);

		self::assertSame([1, 2, 3], $ids);
	}
}
