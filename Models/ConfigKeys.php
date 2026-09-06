<?php

declare(strict_types=1);

namespace tryallthethings\FreshVibes\Models;

use FreshVibesViewExtension;

/**
 * Resolves the mode-dependent user-configuration keys.
 *
 * FreshVibes stores two parallel sets of settings — one for custom mode, one for category mode —
 * distinguished only by a key prefix. Selecting that prefix used to be an inline ternary repeated
 * in every action, which is how the max-height acceptance rules drifted apart between the initial
 * loader and the refresh path. Resolving keys in one place keeps the two sets in step.
 *
 * The stored key strings are unchanged, so existing user configurations keep working.
 */
final class ConfigKeys {

	private function __construct(
		private readonly bool $categoryMode,
	) {
	}

	/** Build a resolver for the given view mode ('categories' or 'custom'). */
	public static function forMode(?string $mode): self {
		return new self($mode === 'categories');
	}

	public function isCategoryMode(): bool {
		return $this->categoryMode;
	}

	/** Per-feed article limit. */
	public function limit(int $feedId): string {
		return $this->prefix(
			FreshVibesViewExtension::CATEGORY_LIMIT_CONFIG_PREFIX,
			FreshVibesViewExtension::LIMIT_CONFIG_PREFIX
		) . $feedId;
	}

	/** Per-feed font size. */
	public function fontSize(int $feedId): string {
		return $this->prefix(
			FreshVibesViewExtension::CATEGORY_FONT_SIZE_CONFIG_PREFIX,
			FreshVibesViewExtension::FONT_SIZE_CONFIG_PREFIX
		) . $feedId;
	}

	/** Per-feed header colour. */
	public function headerColor(int $feedId): string {
		return $this->prefix(
			FreshVibesViewExtension::CATEGORY_FEED_HEADER_COLOR_CONFIG_PREFIX,
			FreshVibesViewExtension::FEED_HEADER_COLOR_CONFIG_PREFIX
		) . $feedId;
	}

	/** Per-feed maximum height. */
	public function maxHeight(int $feedId): string {
		return $this->prefix(
			FreshVibesViewExtension::CATEGORY_MAX_HEIGHT_CONFIG_KEY,
			FreshVibesViewExtension::MAX_HEIGHT_CONFIG_KEY
		) . $feedId;
	}

	/** Per-feed display mode. */
	public function displayMode(int $feedId): string {
		return $this->prefix(
			FreshVibesViewExtension::CATEGORY_FEED_DISPLAY_MODE_CONFIG_PREFIX,
			FreshVibesViewExtension::FEED_DISPLAY_MODE_CONFIG_PREFIX
		) . $feedId;
	}

	/** Every per-feed key, for bulk apply and reset. @return list<string> */
	public function allFeedKeys(int $feedId): array {
		return [
			$this->limit($feedId),
			$this->fontSize($feedId),
			$this->headerColor($feedId),
			$this->maxHeight($feedId),
			$this->displayMode($feedId),
		];
	}

	/** Per-tab background colour. */
	public function tabBgColor(string $tabId): string {
		return $this->prefix(
			FreshVibesViewExtension::CATEGORY_TAB_BG_COLOR_CONFIG_PREFIX,
			FreshVibesViewExtension::TAB_BG_COLOR_CONFIG_PREFIX
		) . $tabId;
	}

	/** Per-tab font colour. */
	public function tabFontColor(string $tabId): string {
		return $this->prefix(
			FreshVibesViewExtension::CATEGORY_TAB_FONT_COLOR_CONFIG_PREFIX,
			FreshVibesViewExtension::TAB_FONT_COLOR_CONFIG_PREFIX
		) . $tabId;
	}

	/** The saved layout. */
	public function layout(): string {
		return $this->prefix(
			FreshVibesViewExtension::CATEGORY_LAYOUT_CONFIG_KEY,
			FreshVibesViewExtension::LAYOUT_CONFIG_KEY
		);
	}

	/** The remembered active tab. */
	public function activeTab(): string {
		return $this->prefix(
			FreshVibesViewExtension::ACTIVE_TAB_CATEGORY_CONFIG_KEY,
			FreshVibesViewExtension::ACTIVE_TAB_CONFIG_KEY
		);
	}

	private function prefix(string $category, string $custom): string {
		return $this->categoryMode ? $category : $custom;
	}
}
