<?php

declare(strict_types=1);

namespace tryallthethings\FreshVibes\Models;

/**
 * Validation and normalisation for the persisted dashboard layout.
 *
 * Every mutation endpoint runs the complete new state through this class before anything is
 * written, so a malformed or partial request is rejected instead of half-applied. The layout is
 * per-user data guarded by CSRF, but it is still request-controlled input and a crafted same-user
 * request must not be able to corrupt or truncate a saved dashboard.
 */
final class LayoutSchema {

	/** Upper bounds for values that would otherwise be unbounded request data. */
	public const MAX_TABS = 100;
	public const MAX_COLUMNS = 6;
	public const MIN_COLUMNS = 1;
	public const MAX_FEEDS_PER_TAB = 1000;
	public const MAX_TAB_NAME_LENGTH = 100;
	public const MAX_ICON_LENGTH = 16;
	public const MAX_TAB_ID_LENGTH = 64;
	public const MAX_LAYOUT_PAYLOAD_BYTES = 262144;
	public const MAX_FEED_HEIGHT = 10000;

	/**
	 * Validate the `columns` object submitted for a single tab.
	 *
	 * @param mixed $decoded Result of `json_decode(..., true)` on the request payload.
	 * @param list<int> $knownFeedIds Feed IDs the user is actually subscribed to.
	 * @param int $numColumns Number of columns the target tab declares.
	 * @param int|null $maxFeeds Ceiling for this tab. Defaults to `MAX_FEEDS_PER_TAB`; callers
	 *                           raise it to the tab's current size so a tab that is already over
	 *                           the ceiling stays editable instead of rejecting every drag.
	 * @return array<string,list<int>>|null Normalised columns, or null when the payload is invalid.
	 */
	public static function validateColumns(mixed $decoded, array $knownFeedIds, int $numColumns, ?int $maxFeeds = null): ?array {
		if (!is_array($decoded)) {
			return null;
		}

		$maxFeeds = max(self::MAX_FEEDS_PER_TAB, $maxFeeds ?? 0);

		$numColumns = max(self::MIN_COLUMNS, min(self::MAX_COLUMNS, $numColumns));
		$known = array_flip($knownFeedIds);
		$columns = self::buildEmptyColumns($numColumns);
		$seen = [];
		$total = 0;

		foreach ($decoded as $colKey => $feedIds) {
			if (!is_string($colKey) || preg_match('/^col([1-9][0-9]?)$/', $colKey, $m) !== 1) {
				return null;
			}
			$index = (int)$m[1];
			if ($index > $numColumns) {
				// Feeds submitted for a column the tab no longer has fall back to the first column
				// rather than disappearing silently.
				$colKey = 'col1';
			}
			if (!is_array($feedIds)) {
				return null;
			}

			foreach ($feedIds as $feedId) {
				if (!is_int($feedId) && !(is_string($feedId) && ctype_digit($feedId))) {
					return null;
				}
				$feedId = (int)$feedId;
				// Silently drop unknown or duplicated feeds: they are stale client state, not an
				// attack, and rejecting the whole request would strand the user's dashboard.
				if ($feedId <= 0 || !isset($known[$feedId]) || isset($seen[$feedId])) {
					continue;
				}
				if (++$total > $maxFeeds) {
					return null;
				}
				$seen[$feedId] = true;
				$columns[$colKey][] = $feedId;
			}
		}

		return $columns;
	}

	/**
	 * Reorder `$layout` to match `$requestedIds`.
	 *
	 * The request must be an exact permutation of the current tab IDs; a subset would silently
	 * delete tabs and duplicates would clone them.
	 *
	 * @param list<array<string,mixed>> $layout
	 * @param list<string> $requestedIds
	 * @return list<array<string,mixed>>|null Reordered layout, or null when the request is not a permutation.
	 */
	public static function reorderTabs(array $layout, array $requestedIds): ?array {
		$requestedIds = array_values(array_filter($requestedIds, static fn(string $id): bool => $id !== ''));
		if ($requestedIds === [] || count($requestedIds) !== count($layout)) {
			return null;
		}
		if (count(array_unique($requestedIds)) !== count($requestedIds)) {
			return null;
		}

		$byId = [];
		foreach ($layout as $tab) {
			if (!isset($tab['id']) || !is_string($tab['id'])) {
				return null;
			}
			$byId[$tab['id']] = $tab;
		}

		$reordered = [];
		foreach ($requestedIds as $id) {
			if (!isset($byId[$id])) {
				return null;
			}
			$reordered[] = $byId[$id];
		}

		return $reordered;
	}

	/** Whether a tab with this ID exists in the layout. */
	public static function tabExists(array $layout, string $tabId): bool {
		foreach ($layout as $tab) {
			if (($tab['id'] ?? null) === $tabId) {
				return true;
			}
		}
		return false;
	}

	/** Collect every feed ID currently placed anywhere in the layout. */
	public static function placedFeedIds(array $layout): array {
		$ids = [];
		foreach ($layout as $tab) {
			foreach ((array)($tab['columns'] ?? []) as $column) {
				foreach ((array)$column as $feedId) {
					$ids[(int)$feedId] = true;
				}
			}
		}
		return array_keys($ids);
	}

	/** @return array<string,list<int>> */
	public static function buildEmptyColumns(int $count): array {
		$count = max(self::MIN_COLUMNS, min(self::MAX_COLUMNS, $count));
		$columns = [];
		for ($i = 1; $i <= $count; $i++) {
			$columns['col' . $i] = [];
		}
		return $columns;
	}

	/**
	 * Redistribute every feed of a tab evenly across `$numColumns` columns.
	 *
	 * @param array<string,mixed> $tab
	 * @return array<string,list<int>>
	 */
	public static function redistributeColumns(array $tab, int $numColumns): array {
		$columns = self::buildEmptyColumns($numColumns);
		$numColumns = count($columns);

		$feeds = [];
		foreach ((array)($tab['columns'] ?? []) as $column) {
			foreach ((array)$column as $feedId) {
				$feeds[] = $feedId;
			}
		}

		foreach ($feeds as $i => $feedId) {
			$columns['col' . (($i % $numColumns) + 1)][] = $feedId;
		}

		return $columns;
	}

	/**
	 * Remove feeds that appear in more than one place, keeping the first occurrence.
	 *
	 * Uses a keyed set rather than repeated `in_array()` scans, which were quadratic in the number
	 * of placed feeds.
	 *
	 * @param list<array<string,mixed>> $layout
	 */
	public static function deduplicate(array &$layout): void {
		$seen = [];

		foreach ($layout as &$tab) {
			if (!isset($tab['columns']) || !is_array($tab['columns'])) {
				continue;
			}
			foreach ($tab['columns'] as &$column) {
				if (!is_array($column)) {
					$column = [];
					continue;
				}
				$clean = [];
				foreach ($column as $feedId) {
					$key = (string)$feedId;
					if (isset($seen[$key])) {
						continue;
					}
					$seen[$key] = true;
					$clean[] = $feedId;
				}
				$column = $clean;
			}
			unset($column);
		}
		unset($tab);
	}

	/**
	 * Total feeds placed across the whole layout.
	 *
	 * `validateColumns()` bounds a single tab, but moves and redistribution write layouts without
	 * going through it, so the whole-layout total is checked separately before every save.
	 *
	 * @param list<array<string,mixed>> $layout
	 */
	public static function totalPlacedFeeds(array $layout): int {
		$total = 0;
		foreach ($layout as $tab) {
			foreach ((array)($tab['columns'] ?? []) as $column) {
				$total += count((array)$column);
			}
		}
		return $total;
	}

	/**
	 * Whether a complete layout is within the declared structural limits.
	 *
	 * Checks the per-tab ceiling as well as the tab count. `validateColumns()` enforces the per-tab
	 * limit for the one path that goes through it, but moves, redistribution and tab deletion build
	 * layouts directly, so the limit is re-checked here on the shared write path.
	 *
	 * When `$previous` is given, a dimension that the stored layout already exceeds is judged
	 * against that stored value instead of against the constant: an oversized layout may be kept
	 * or reduced, never pushed further. Without that allowance an account over a ceiling — a bulk
	 * import, more categories than `MAX_TABS`, an upgrade from a release that had no ceilings —
	 * could not use the dashboard to get back under it.
	 *
	 * @param list<array<string,mixed>> $layout
	 * @param list<array<string,mixed>>|null $previous The layout currently stored, if any.
	 */
	public static function withinLimits(array $layout, ?array $previous = null): bool {
		$maxTabs = self::MAX_TABS;
		$maxFeedsPerTab = self::MAX_FEEDS_PER_TAB;

		if ($previous !== null) {
			$maxTabs = max($maxTabs, count($previous));
			$maxFeedsPerTab = max($maxFeedsPerTab, self::largestTabSize($previous));
		}

		if (count($layout) > $maxTabs) {
			return false;
		}

		return self::largestTabSize($layout) <= $maxFeedsPerTab;
	}

	/**
	 * Number of feeds placed in the fullest tab of a layout.
	 *
	 * @param list<array<string,mixed>> $layout
	 */
	public static function largestTabSize(array $layout): int {
		$largest = 0;
		foreach ($layout as $tab) {
			$feedsInTab = 0;
			foreach ((array)($tab['columns'] ?? []) as $column) {
				$feedsInTab += count((array)$column);
			}
			$largest = max($largest, $feedsInTab);
		}
		return $largest;
	}

	/**
	 * Number of feeds placed in the tab with this ID, or 0 when it holds none.
	 *
	 * @param list<array<string,mixed>> $layout
	 */
	public static function tabSize(array $layout, string $tabId): int {
		foreach ($layout as $tab) {
			if (($tab['id'] ?? null) !== $tabId) {
				continue;
			}
			$feedsInTab = 0;
			foreach ((array)($tab['columns'] ?? []) as $column) {
				$feedsInTab += count((array)$column);
			}
			return $feedsInTab;
		}
		return 0;
	}

	/**
	 * Validate a submitted category order against the categories that actually exist.
	 *
	 * Requires an exact permutation: a subset would leave the omitted categories on their old
	 * positions and collide with the newly assigned ones, and an empty value used to arrive as
	 * `['']`, update nothing and still report success.
	 *
	 * @param list<string> $requestedIds Raw `cat-<id>` strings from the request.
	 * @param list<int> $existingIds Category IDs the user actually has.
	 * @return list<int>|null Ordered category IDs, or null when the request is not a permutation.
	 */
	public static function orderedCategoryIds(array $requestedIds, array $existingIds): ?array {
		$parsed = [];
		foreach ($requestedIds as $raw) {
			if (preg_match('/^cat-([1-9][0-9]*)$/', trim($raw), $m) !== 1) {
				return null;
			}
			$parsed[] = (int)$m[1];
		}

		if ($parsed === [] || count($parsed) !== count($existingIds)) {
			return null;
		}

		$sortedRequested = $parsed;
		$sortedExisting = $existingIds;
		sort($sortedRequested);
		sort($sortedExisting);
		if ($sortedRequested !== $sortedExisting) {
			return null;
		}

		return $parsed;
	}

	/**
	 * Trim a user-supplied display string and reject it when empty, over-long or malformed.
	 *
	 * The value is measured and stored as the text the user typed. Control characters are rejected
	 * rather than stripped, so a name can never carry a line break or a NUL into the stored
	 * configuration, and invalid UTF-8 is rejected because `mb_strlen()` cannot measure it.
	 */
	public static function normalizeName(string $value, int $maxLength = self::MAX_TAB_NAME_LENGTH): ?string {
		if (!mb_check_encoding($value, 'UTF-8')) {
			return null;
		}
		$value = trim($value);
		if ($value === '' || mb_strlen($value) > $maxLength) {
			return null;
		}
		if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
			return null;
		}
		return $value;
	}
}
