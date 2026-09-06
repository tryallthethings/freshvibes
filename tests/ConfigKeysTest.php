<?php

declare(strict_types=1);

namespace tryallthethings\FreshVibes\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tryallthethings\FreshVibes\Models\ConfigKeys;

/**
 * Tests for the mode-dependent configuration key resolver.
 *
 * The literal key strings are asserted rather than derived, because they are the on-disk format of
 * every existing user's saved settings. A refactor that changes one silently resets that setting
 * for everybody on upgrade, so these tests exist to make such a change fail loudly.
 */
#[CoversClass(ConfigKeys::class)]
final class ConfigKeysTest extends TestCase {

	/**
	 * @return array<string,array{callable(ConfigKeys):string,string,string}>
	 */
	public static function feedKeyProvider(): array {
		return [
			'limit' => [
				static fn(ConfigKeys $k): string => $k->limit(7),
				'freshvibes_limit_feedid_7',
				'freshvibes_category_limit_feedid_7',
			],
			'fontSize' => [
				static fn(ConfigKeys $k): string => $k->fontSize(7),
				'freshvibes_fontsize_feedid_7',
				'freshvibes_category_fontsize_feedid_7',
			],
			'headerColor' => [
				static fn(ConfigKeys $k): string => $k->headerColor(7),
				'freshvibes_feed_headercolor_7',
				'freshvibes_category_feed_headercolor_7',
			],
			// Note the missing separator before the ID: the constant ends in "height", not
			// "height_". That is the format already on disk, so it must not be "tidied up".
			'maxHeight' => [
				static fn(ConfigKeys $k): string => $k->maxHeight(7),
				'freshvibes_feed_max_height7',
				'freshvibes_category_feed_max_height7',
			],
			'displayMode' => [
				static fn(ConfigKeys $k): string => $k->displayMode(7),
				'freshvibes_display_mode_feedid_7',
				'freshvibes_category_display_mode_feedid_7',
			],
		];
	}

	/** @param callable(ConfigKeys):string $resolve */
	#[DataProvider('feedKeyProvider')]
	public function testFeedKeysMatchTheStoredFormat(callable $resolve, string $custom, string $category): void {
		self::assertSame($custom, $resolve(ConfigKeys::forMode('custom')));
		self::assertSame($category, $resolve(ConfigKeys::forMode('categories')));
	}

	public function testTabKeysMatchTheStoredFormat(): void {
		$custom = ConfigKeys::forMode('custom');
		$category = ConfigKeys::forMode('categories');

		self::assertSame('freshvibes_tab_bgcolor_tab-1', $custom->tabBgColor('tab-1'));
		self::assertSame('freshvibes_category_tab_bgcolor_cat-1', $category->tabBgColor('cat-1'));
		self::assertSame('freshvibes_tab_fontcolor_tab-1', $custom->tabFontColor('tab-1'));
		self::assertSame('freshvibes_category_tab_fontcolor_cat-1', $category->tabFontColor('cat-1'));
	}

	public function testLayoutAndActiveTabKeysMatchTheStoredFormat(): void {
		self::assertSame('freshvibes_layout', ConfigKeys::forMode('custom')->layout());
		self::assertSame('freshvibes_category_layout', ConfigKeys::forMode('categories')->layout());
		self::assertSame('freshvibes_active_tab', ConfigKeys::forMode('custom')->activeTab());
		self::assertSame('freshvibes_category_active_tab', ConfigKeys::forMode('categories')->activeTab());
	}

	/**
	 * Only the exact string 'categories' selects category mode; anything else is custom mode, which
	 * matches how the controller previously compared the value inline.
	 *
	 * @return array<string,array{?string,bool}>
	 */
	public static function modeProvider(): array {
		return [
			'categories' => ['categories', true],
			'custom' => ['custom', false],
			'null' => [null, false],
			'empty' => ['', false],
			'wrong case' => ['Categories', false],
			'unknown' => ['something', false],
		];
	}

	#[DataProvider('modeProvider')]
	public function testModeDetection(?string $mode, bool $expected): void {
		self::assertSame($expected, ConfigKeys::forMode($mode)->isCategoryMode());
	}

	public function testCustomAndCategoryKeysNeverCollide(): void {
		$custom = ConfigKeys::forMode('custom');
		$category = ConfigKeys::forMode('categories');

		self::assertSame([], array_intersect($custom->allFeedKeys(7), $category->allFeedKeys(7)));
	}

	public function testAllFeedKeysCoversEveryPerFeedSetting(): void {
		$keys = ConfigKeys::forMode('custom');

		self::assertSame([
			$keys->limit(7),
			$keys->fontSize(7),
			$keys->headerColor(7),
			$keys->maxHeight(7),
			$keys->displayMode(7),
		], $keys->allFeedKeys(7));
	}
}
