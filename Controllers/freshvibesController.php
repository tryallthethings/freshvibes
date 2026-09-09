<?php

declare(strict_types=1);

use tryallthethings\FreshVibes\Models\ConfigKeys;
use tryallthethings\FreshVibes\Models\LayoutSchema;
use tryallthethings\FreshVibes\Models\Sanitizer;

class FreshExtension_freshvibes_Controller extends Minz_ActionController {

	/**
	 * @var tryallthethings\FreshVibes\Models\View
	 * @phpstan-ignore property.phpDocType
	 */
	protected $view;

	public function __construct() {
		parent::__construct(tryallthethings\FreshVibes\Models\View::class);
	}

	#[\Override]
	public function firstAction(): void {
		$this->view->html_url = Minz_Url::display([
			'c' => FreshVibesViewExtension::CONTROLLER_NAME_BASE,
			'a' => 'index',
		], 'html', 'root');
	}

	public function indexAction() {
		$this->noCacheHeaders();
		$this->initializeDefaultSettings();

		$feedDAO = FreshRSS_Factory::createFeedDao();
		$entryDAO = FreshRSS_Factory::createEntryDao();

		try {
			FreshRSS_Context::updateUsingRequest(true);
		} catch (FreshRSS_Context_Exception $e) {
			Minz_Error::error(404);
			return;
		}

		$feeds = $feedDAO->listFeeds();
		$userConf = FreshRSS_Context::userConf();
		$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
		$keys = ConfigKeys::forMode($mode);
		$currentState = FreshRSS_Context::$state;
		$feedsData = [];
		$dateFormat = $userConf->attributeString(FreshVibesViewExtension::DATE_FORMAT_CONFIG_KEY)
			?: FreshVibesViewExtension::DEFAULT_DATE_FORMAT;
		$entriesCap = $this->entriesCap($userConf);

		foreach ($feeds as $feed) {
			$feedId = $feed->id();
			$limitKey = $keys->limit($feedId);
			$fontSizeKey = $keys->fontSize($feedId);

			$limit = $userConf->attributeInt($limitKey) ?? $userConf->attributeString($limitKey);
			$limit = is_numeric($limit) ? (int)$limit : $limit;
			if (!in_array($limit, FreshVibesViewExtension::ALLOWED_LIMIT_VALUES, true)) {
				$limit = FreshVibesViewExtension::DEFAULT_ARTICLES_PER_FEED;
			}
			$queryLimit = $this->resolveQueryLimit($limit, $entriesCap);

			$fontSize = $userConf->attributeString($fontSizeKey);
			if (!in_array($fontSize, FreshVibesViewExtension::ALLOWED_FONT_SIZES, true)) {
				$fontSize = FreshVibesViewExtension::DEFAULT_FONT_SIZE;
			}

			$maxHeightKey = $keys->maxHeight($feedId);
			$maxHeight = $userConf->attributeString($maxHeightKey);

			if (!in_array($maxHeight, FreshVibesViewExtension::ALLOWED_MAX_HEIGHTS_CONFIG_KEY, true) && !is_numeric($maxHeight)) {
				$maxHeight = FreshVibesViewExtension::DEFAULT_MAX_HEIGHT_CONFIG_KEY;
			}

			$headerColorKey = $keys->headerColor($feedId);
			$headerColor = $userConf->hasParam($headerColorKey) ? $userConf->attributeString($headerColorKey) : '';

			$displayModeKey = $keys->displayMode($feedId);
			$displayMode = $userConf->attributeString($displayModeKey);

			if (!in_array($displayMode, FreshVibesViewExtension::ALLOWED_DISPLAY_MODES, true)) {
				$displayMode = FreshVibesViewExtension::DEFAULT_DISPLAY_MODE;
			}

			try {
				// Get sorting from FreshRSS context
				$sort = FreshRSS_Context::$sort;
				$order = FreshRSS_Context::$order;

				$entryGenerator = $entryDAO->listWhere(
					type: 'f',
					id: $feedId,
					state: $currentState,
					filters: null,
					id_min: '0',
					id_max: '0',
					sort: $sort,
					order: $order,
					continuation_id: '0',
					continuation_values: [0],
					limit: $queryLimit,
					offset: 0
				);
				$entries = [];

				foreach ($entryGenerator as $entry) {
					if ($entry instanceof FreshRSS_Entry) {
						$entries[] = $this->serializeEntry($entry, $feedId, $dateFormat);
					}
				}
			} catch (Exception $e) {
				error_log('FreshVibesView error in indexAction for feed ' . $feedId . ': ' . $e->getMessage());
				$entries = ['error' => sprintf(_t('ext.FreshVibesView.error_loading_entries_logs'), $feedId)];
			}

			$feedsData[$feedId] = [
				'id' => $feedId,
				'name' => html_entity_decode($feed->name(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
				'favicon' => $feed->favicon(),
				'website' => Sanitizer::safeUrl($feed->website()),
				'entries' => $entries,
				'currentLimit' => $limit,
				'currentFontSize' => $fontSize,
				'nbUnread' => $feed->nbNotRead(),
				'currentHeaderColor' => $headerColor,
				'currentMaxHeight' => $maxHeight,
				'currentDisplayMode' => $displayMode,
			];
		}

		$controllerParam = strtolower(FreshVibesViewExtension::CONTROLLER_NAME_BASE);
		$this->view->currentSort = FreshRSS_Context::$sort;
		$this->view->currentOrder = FreshRSS_Context::$order;
		$this->view->feedsData = $feedsData;
		$this->view->getLayoutUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'getlayout'], 'json', false);
		$this->view->saveLayoutUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'savelayout'], 'json', false);
		$this->view->saveFeedSettingsUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'savefeedsettings'], 'json', false);
		$this->view->tabActionUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'updatetab'], 'json', false);
		$this->view->moveFeedUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'movefeed'], 'json', false);
		$this->view->setActiveTabUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'setactivetab'], 'json', false);
		$this->view->markFeedReadUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'markfeedread'], 'json', false);
		$this->view->markTabReadUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'marktabread'], 'json', false);
		$this->view->markReadUrl = Minz_Url::display(['c' => 'entry', 'a' => 'read'], 'json', false);
		$this->view->bookmarkUrl = Minz_Url::display(['c' => 'entry', 'a' => 'bookmark'], 'json', false);
		$this->view->searchAuthorUrl = Minz_Url::display(['a' => 'normal'], 'html', false);
		$this->view->searchTagUrl = Minz_Url::display(['a' => 'normal'], 'html', false);
		$this->view->animationsEnabled = $userConf->attributeBool(FreshVibesViewExtension::ANIMATIONS_ENABLED_CONFIG_KEY);
		$this->view->emptyFeedsDisplay = $userConf->attributeString(FreshVibesViewExtension::EMPTY_FEEDS_DISPLAY_CONFIG_KEY) ?: 'show';

		$this->view->viewMode = $mode;
		$this->view->rss_title = _t('ext.FreshVibesView.title');
		$this->view->refreshEnabled = $userConf->attributeBool(FreshVibesViewExtension::REFRESH_ENABLED_CONFIG_KEY);
		$this->view->refreshInterval = $userConf->attributeInt(FreshVibesViewExtension::REFRESH_INTERVAL_CONFIG_KEY);
		$this->view->feedUrl = Minz_Url::display([], 'html', false) . '?get=f_';
		$this->view->categories = FreshRSS_Context::categories();
		$this->view->confirmTabDelete = $userConf->attributeBool(FreshVibesViewExtension::CONFIRM_TAB_DELETE_CONFIG_KEY);
		$this->view->entryClickMode = $userConf->attributeString(FreshVibesViewExtension::ENTRY_CLICK_MODE_CONFIG_KEY);
		$this->view->dateMode = $userConf->attributeString(FreshVibesViewExtension::DATE_MODE_CONFIG_KEY);
		$this->view->confirmMarkRead = $userConf->attributeBool(FreshVibesViewExtension::CONFIRM_MARK_READ_CONFIG_KEY);
		$this->view->feedSettingsUrl = Minz_Url::display() . '?c=subscription&a=feed&id=';
		$this->view->categorySettingsUrl = Minz_Url::display() . '?c=category&a=update&id=';
		$this->view->dashboardLayout = $userConf->attributeString(FreshVibesViewExtension::DASHBOARD_LAYOUT_CONFIG_KEY) ?: 'tabs';
		$this->view->allowCategorySort = $userConf->attributeBool(FreshVibesViewExtension::ALLOW_CATEGORY_SORT_CONFIG_KEY) ?? false;
		$this->view->saveCategoryOrderUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'savecategoryorder'], 'json', false);

		$tags = FreshRSS_Context::labels(true);
		$this->view->tags = $tags;
		$nbUnreadTags = 0;
		foreach ($tags as $tag) {
			$nbUnreadTags += $tag->nbUnread();
		}
		$this->view->nbUnreadTags = $nbUnreadTags;

		$this->view->_path(FreshVibesViewExtension::CONTROLLER_NAME_BASE . '/index.phtml');
	}

	/**
	 * Seed and migrate this user's settings.
	 *
	 * This writes during a GET, which is a deliberate and documented exception to GET idempotence.
	 * FreshRSS extensions have no install/upgrade hook that runs per user — `Minz_Extension` only
	 * offers `install()`/`uninstall()` for the extension as a whole — so first-run defaults and
	 * type migrations have nowhere else to happen.
	 *
	 * The write is self-limiting only because every seeded value is non-null. `_attribute($key,
	 * null)` *removes* a key rather than storing a present-null, and `hasParam()` is an `isset()`,
	 * so a null "default" would stay absent and be rewritten on every single read. The two layout
	 * keys are therefore not seeded here at all: their absence is meaningful and `getLayout()`
	 * already builds them on demand. Existence is also checked before reading, because
	 * `Minz_Configuration::param()` logs a warning for a key it does not have.
	 *
	 * Nothing here is attacker-selected: every value written is a constant from this file, and the
	 * keys are derived from the user's own feeds. Moving this to an explicit setup action would be
	 * the cleaner design and is tracked as future work.
	 */
	private function initializeDefaultSettings(): void {
		$userConf = FreshRSS_Context::userConf();
		$configChanged = false;

		$defaults = [
			FreshVibesViewExtension::DATE_FORMAT_CONFIG_KEY => 'Y-m-d H:i',
			FreshVibesViewExtension::REFRESH_ENABLED_CONFIG_KEY => false,
			FreshVibesViewExtension::REFRESH_INTERVAL_CONFIG_KEY => 15,
			FreshVibesViewExtension::CONFIRM_TAB_DELETE_CONFIG_KEY => true,
			FreshVibesViewExtension::ENTRY_CLICK_MODE_CONFIG_KEY => 'modal',
			FreshVibesViewExtension::DATE_MODE_CONFIG_KEY => 'absolute',
			FreshVibesViewExtension::CONFIRM_MARK_READ_CONFIG_KEY => true,
			FreshVibesViewExtension::NEW_FEED_POSITION_CONFIG_KEY => 'bottom',
			FreshVibesViewExtension::HIDE_SIDEBAR_CONFIG_KEY => false,
			FreshVibesViewExtension::HIDE_SUBSCRIPTION_CONTROL_CONFIG_KEY => false,
			FreshVibesViewExtension::MODE_CONFIG_KEY => 'custom',
			FreshVibesViewExtension::DASHBOARD_LAYOUT_CONFIG_KEY => 'tabs',
			FreshVibesViewExtension::ANIMATIONS_ENABLED_CONFIG_KEY => true,
			FreshVibesViewExtension::EMPTY_FEEDS_DISPLAY_CONFIG_KEY => 'show',
		];

		foreach ($defaults as $key => $value) {
			if (!$userConf->hasParam($key)) {
				// The key is genuinely absent. Seed it with the declared default.
				$userConf->_attribute($key, $value);
				$configChanged = true;
				continue;
			}
			if (is_bool($value) && $userConf->attributeBool($key) === null) {
				// A boolean setting whose stored type is wrong (e.g. an int written by an older
				// release). Coerce the existing value rather than discarding the user's choice.
				$userConf->_attribute($key, (bool)$userConf->param($key, false));
				$configChanged = true;
			}
		}
		$feedDAO = FreshRSS_Factory::createFeedDao();
		$feeds = $feedDAO->listFeeds();
		$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
		$keys = ConfigKeys::forMode($mode);

		foreach ($feeds as $feed) {
			$feedId = $feed->id();
			$feedDefaults = [
				$keys->limit($feedId) => FreshVibesViewExtension::DEFAULT_ARTICLES_PER_FEED,
				$keys->fontSize($feedId) => FreshVibesViewExtension::DEFAULT_FONT_SIZE,
				$keys->maxHeight($feedId) => FreshVibesViewExtension::DEFAULT_MAX_HEIGHT_CONFIG_KEY,
				$keys->displayMode($feedId) => FreshVibesViewExtension::DEFAULT_DISPLAY_MODE,
			];

			foreach ($feedDefaults as $key => $value) {
				if (!$userConf->hasParam($key)) {
					$userConf->_attribute($key, $value);
					$configChanged = true;
				}
			}
		}

		if ($configChanged && !$userConf->save()) {
			Minz_Log::warning('FreshVibesView: could not persist default settings for this user.');
		}
	}

	private function getLayout(): array {
		$userConf = FreshRSS_Context::userConf();
		$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
		$keys = ConfigKeys::forMode($mode);
		$layoutKey = $keys->layout();
		$layout = $userConf->attributeArray($layoutKey);

		// Get the new feed position setting
		$newFeedPosition = $userConf->attributeString(FreshVibesViewExtension::NEW_FEED_POSITION_CONFIG_KEY) ?? 'bottom';

		if ($mode === 'categories') {
			// Always reorder categories according to their position
			$categories = FreshRSS_Context::categories();
			$existingLayout = $layout ?: [];
			$layout = [];

			// Build a map of existing tab data by category ID
			$existingTabsMap = [];
			foreach ($existingLayout as $tab) {
				$existingTabsMap[$tab['id']] = $tab;
			}

			// Sort categories by position
			$sortedCategories = [];
			foreach ($categories as $cat) {
				$position = $cat->attributeInt('position');
				$sortedCategories[] = [
					'category' => $cat,
					'position' => $position !== null ? $position : PHP_INT_MAX
				];
			}
			usort($sortedCategories, function ($a, $b) {
				return $a['position'] <=> $b['position'];
			});

			// Build layout in sorted order
			foreach ($sortedCategories as $catData) {
				$cat = $catData['category'];
				$tabId = 'cat-' . $cat->id();

				if (isset($existingTabsMap[$tabId])) {
					// Use existing tab but ensure name is updated
					$existingTab = $existingTabsMap[$tabId];
					$existingTab['name'] = html_entity_decode($cat->name(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

					// Ensure feeds are in the correct category tab
					$columns = $existingTab['columns'];

					// Ensure all columns are arrays
					foreach ($columns as $colKey => &$colFeeds) {
						if (!is_array($colFeeds)) {
							$colFeeds = [];
						}
					}
					unset($colFeeds);

					$feeds = $cat->feeds();
					$feedIds = [];
					foreach ($feeds as $feed) {
						$feedIds[] = $feed->id();
					}

					// Remove feeds that don't belong to this category
					foreach ($columns as $colKey => &$colFeeds) {
						if (!is_array($colFeeds)) {
							$colFeeds = [];
							continue;
						}
						$colFeeds = array_values(array_intersect($colFeeds, $feedIds));
					}
					unset($colFeeds);

					// Add any missing feeds from this category
					$existingFeedIds = [];
					foreach ($columns as $colFeeds) {
						if (is_array($colFeeds)) {
							foreach ($colFeeds as $fid) {
								$existingFeedIds[strval($fid)] = true;
							}
						}
					}
					$missingFeeds = array_diff($feedIds, array_keys($existingFeedIds));

					if (!empty($missingFeeds)) {
						if ($newFeedPosition === 'top') {
							// A saved layout may hold no col1 at all (an empty columns object used to
							// be accepted), which made this array_merge() raise a TypeError.
							$columns['col1'] = array_merge(array_values($missingFeeds), (array)($columns['col1'] ?? []));
						} else {
							// Add at the bottom (existing behavior)
							$numCols = max(1, (int)($existingTab['num_columns'] ?? FreshVibesViewExtension::DEFAULT_TAB_COLUMNS));
							$i = count($existingFeedIds);
							foreach ($missingFeeds as $feedId) {
								$colKey = 'col' . (($i % $numCols) + 1);
								if (!isset($columns[$colKey]) || !is_array($columns[$colKey])) {
									$columns[$colKey] = [];
								}
								$columns[$colKey][] = $feedId;
								$i++;
							}
						}
					}

					$existingTab['columns'] = $columns;
					$layout[] = $existingTab;
				} else {
					// Create new tab for this category
					$numCols = FreshVibesViewExtension::DEFAULT_TAB_COLUMNS;
					$columns = $this->buildEmptyColumns($numCols);
					$feeds = $cat->feeds();

					if ($newFeedPosition === 'top') {
						// Add all feeds to the first column when creating new tab
						$feedIds = [];
						foreach ($feeds as $feed) {
							$feedIds[] = $feed->id();
						}
						$columns['col1'] = $feedIds;
					} else {
						// Distribute feeds across columns (existing behavior)
						$i = 0;
						foreach ($feeds as $feed) {
							$colKey = 'col' . (($i % $numCols) + 1);
							$columns[$colKey][] = $feed->id();
							$i++;
						}
					}

					$layout[] = [
						'id' => $tabId,
						'name' => html_entity_decode($cat->name(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
						'icon' => '',
						'icon_color' => '',
						'num_columns' => $numCols,
						'columns' => $columns,
					];
				}
			}

			// Reconciling categories runs on every read, including plain GETs. Only persist when the
			// result differs from what is already stored, instead of writing on each request.
			if ($layout !== $existingLayout) {
				$this->persistGeneratedLayout($layout);
			}
			return $layout;
		}

		if ($layout === null) {
			$layout = $this->buildInitialLayout($newFeedPosition);
			$this->persistGeneratedLayout($layout);
		} else {
			// Reconcile feeds subscribed to since the layout was last written. Leaving them out
			// used to make the stored layout disagree with the dashboard: the client renders an
			// unplaced feed into the first tab regardless, so the next drag in that tab submitted
			// more feeds than the stored tab was allowed to hold and every save was rejected.
			$unplaced = array_values(array_diff($this->subscribedFeedIds(), LayoutSchema::placedFeedIds($layout)));
			if ($unplaced !== []) {
				$layout = $this->placeNewSubscriptions($layout, $unplaced, $newFeedPosition);
				$this->persistGeneratedLayout($layout);
			}
		}

		// Ensure all layout columns are arrays before returning
		foreach ($layout as &$tab) {
			if (isset($tab['columns']) && is_array($tab['columns'])) {
				$seenFeeds = [];
				foreach ($tab['columns'] as &$column) {
					if (!is_array($column)) {
						$column = [];
					} else {
						// Remove duplicates within and across columns
						$uniqueColumn = [];
						foreach ($column as $feedId) {
							if (!in_array($feedId, $seenFeeds, true)) {
								$uniqueColumn[] = $feedId;
								$seenFeeds[] = $feedId;
							}
						}
						$column = $uniqueColumn;
					}
				}
			}
		}

		return $layout;
	}

	/**
	 * Build the first custom-mode layout for an account that has none yet.
	 *
	 * Feeds are chunked so no generated tab exceeds the per-tab ceiling. Placing every
	 * subscription in a single tab used to produce a layout that the write path then rejected,
	 * leaving accounts above the ceiling unable to initialise the dashboard at all.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function buildInitialLayout(string $newFeedPosition): array {
		$feedIds = $this->subscribedFeedIds();
		$chunks = $feedIds === [] ? [[]] : array_chunk($feedIds, LayoutSchema::MAX_FEEDS_PER_TAB);

		$layout = [];
		foreach ($chunks as $index => $chunkIds) {
			$tab = $this->buildGeneratedTab($index, $layout);
			// Placement honours the user's new-feed preference, exactly as it does later when a
			// subscription is added to an existing layout.
			$onlyTab = [$tab];
			LayoutSchema::placeFeeds($onlyTab, $chunkIds, $newFeedPosition);
			$layout[] = $onlyTab[0];
		}

		return $layout;
	}

	/**
	 * An empty tab for a generated layout, numbered from zero.
	 *
	 * @param list<array<string,mixed>> $existing Tabs already in the layout, so the ID is unique.
	 * @return array<string,mixed>
	 */
	private function buildGeneratedTab(int $index, array $existing = []): array {
		$baseName = _t('ext.FreshVibesView.default_tab_name');
		$id = 'tab-' . microtime(true) . '-' . $index;
		$suffix = 0;
		while (LayoutSchema::tabExists($existing, $id)) {
			$id = 'tab-' . microtime(true) . '-' . $index . '-' . (++$suffix);
		}

		return [
			'id' => $id,
			'name' => $index === 0 ? $baseName : $baseName . ' ' . ($index + 1),
			'icon' => '',
			'icon_color' => '',
			'num_columns' => FreshVibesViewExtension::DEFAULT_TAB_COLUMNS,
			'columns' => $this->buildEmptyColumns(FreshVibesViewExtension::DEFAULT_TAB_COLUMNS),
		];
	}

	/**
	 * Place newly subscribed feeds, adding overflow tabs when the existing ones are full.
	 *
	 * @param list<array<string,mixed>> $layout
	 * @param list<int> $feedIds
	 * @return list<array<string,mixed>>
	 */
	private function placeNewSubscriptions(array $layout, array $feedIds, string $newFeedPosition): array {
		$layout = LayoutSchema::reconcileFeeds(
			$layout,
			$feedIds,
			$newFeedPosition,
			fn(int $index, array $soFar): array => $this->buildGeneratedTab($index, $soFar)
		);

		if (LayoutSchema::largestTabSize($layout) > LayoutSchema::MAX_FEEDS_PER_TAB) {
			Minz_Log::warning(
				'FreshVibesView: tab ceiling reached, so new subscriptions were placed beyond the '
				. 'per-tab limit rather than left unreachable.'
			);
		}

		return $layout;
	}

	/**
	 * Persist a layout produced by a state-changing request.
	 *
	 * This is the single request-side write path, so the declared tab/feed ceilings are enforced
	 * here rather than in each caller; `validateColumns()` only ever sees one tab's worth of data.
	 * The ceilings are applied to *growth* rather than to absolute size: an account whose stored
	 * layout is already over a ceiling — a large import, many categories, an upgrade from a
	 * release that had no ceilings — must still be able to rename, reorder and shrink its tabs,
	 * otherwise the only state the UI can repair is the one it refuses to save.
	 */
	private function saveLayout(array $layout): void {
		// Clean up any duplicates before saving
		$this->deduplicateLayout($layout);
		if (!LayoutSchema::withinLimits($layout, $this->storedLayout())) {
			$this->failWithBadRequest(_t('ext.FreshVibesView.error_layout_too_large'));
		}
		if (!$this->writeLayout($layout)) {
			$this->failWithPersistenceError('saveLayout');
		}
	}

	/**
	 * Persist a layout the extension generated from the user's own feeds and categories.
	 *
	 * Nothing here comes from the request, so the ceilings are not applied: refusing to store a
	 * reconciled layout would make the dashboard unreadable for exactly the accounts that are
	 * hardest to repair. A failure is logged and the response continues with the in-memory
	 * layout, because these writes happen during reads.
	 */
	private function persistGeneratedLayout(array $layout): void {
		if (!LayoutSchema::withinLimits($layout)) {
			Minz_Log::warning('FreshVibesView: generated layout exceeds the declared structural limits.');
		}
		if (!$this->writeLayout($layout)) {
			Minz_Log::warning('FreshVibesView: could not persist the generated layout.');
		}
	}

	/** Deduplicate and store a layout. @return bool Whether the configuration was persisted. */
	private function writeLayout(array $layout): bool {
		$this->deduplicateLayout($layout);
		$userConf = FreshRSS_Context::userConf();
		$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
		$keys = ConfigKeys::forMode($mode);
		$userConf->_attribute($keys->layout(), $layout);
		return $userConf->save();
	}

	/**
	 * The layout exactly as stored, without reconciliation.
	 *
	 * Used as the baseline for the growth check, so reading it must not trigger the generation
	 * path that `getLayout()` runs.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function storedLayout(): array {
		$userConf = FreshRSS_Context::userConf();
		$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
		$keys = ConfigKeys::forMode($mode);
		return array_values($userConf->attributeArray($keys->layout()) ?? []);
	}

	/**
	 * Decode tab names for a response body.
	 *
	 * Names are stored HTML-escaped and the client renders them with `textContent`, so every
	 * response carrying a layout has to hand back text. `getLayoutAction()` already did this; the
	 * mutation endpoints that echo `new_layout` did not, so a tab called `Tech & Science` turned
	 * into `Tech &amp; Science` on screen after a move or a column change, until the next reload.
	 *
	 * @param list<array<string,mixed>> $layout
	 * @return list<array<string,mixed>>
	 */
	private function layoutForResponse(array $layout): array {
		foreach ($layout as &$tab) {
			if (isset($tab['name']) && is_string($tab['name'])) {
				$tab['name'] = html_entity_decode($tab['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
			}
		}
		unset($tab);
		return $layout;
	}

	public function getLayoutAction() {
		$this->noCacheHeaders();
		header('Content-Type: application/json');
		try {
			$layout = $this->getLayout();
			$userConf = FreshRSS_Context::userConf();
			$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
			$keys = ConfigKeys::forMode($mode);
			$activeTabKey = $keys->activeTab();
			$activeTabId = $userConf->attributeString($activeTabKey);
			$activeTabExists = false;
			if ($activeTabId != '') {
				foreach ($layout as $tab) {
					if ($tab['id'] === $activeTabId) {
						$activeTabExists = true;
						break;
					}
				}
			}
			if (!$activeTabExists && !empty($layout)) {
				// Remembering the first tab happens during a read, so a failed write must not turn
				// the layout response into an error; the chosen tab is still returned below.
				$activeTabId = $layout[0]['id'];
				$userConf->_attribute($activeTabKey, $activeTabId);
				if (!$userConf->save()) {
					Minz_Log::warning('FreshVibesView: could not persist the active tab.');
				}
			}

			// Build the id -> unread map once; the previous code issued one searchById() per placed
			// feed on every layout read.
			$unreadByFeedId = [];
			foreach (FreshRSS_Factory::createFeedDao()->listFeeds() as $feed) {
				$unreadByFeedId[$feed->id()] = $feed->nbNotRead();
			}

			$layout = $this->layoutForResponse($layout);
			foreach ($layout as &$tab) {
				$bgColorKey = $keys->tabBgColor($tab['id']);
				$fontColorKey = $keys->tabFontColor($tab['id']);

				$tab['bg_color'] = $userConf->hasParam($bgColorKey) ? $userConf->attributeString($bgColorKey) : '';
				$tab['font_color'] = $userConf->hasParam($fontColorKey) ? $userConf->attributeString($fontColorKey) : '';

				// Calculate unread count for tab
				$tabUnreadCount = 0;
				foreach ($tab['columns'] as $column) {
					foreach ($column as $feedId) {
						$tabUnreadCount += $unreadByFeedId[(int)$feedId] ?? 0;
					}
				}
				$tab['unread_count'] = $tabUnreadCount;
			}

			echo json_encode(['layout' => $layout, 'active_tab_id' => $activeTabId]);
		} catch (Exception $e) {
			http_response_code(500);
			echo json_encode(['error' => _t('ext.FreshVibesView.error_server_loading_layout')]);
		}
		exit;
	}

	public function saveLayoutAction() {
		$this->validatePostRequest();

		// The payload is JSON, so it has to be read unescaped: the default `paramString()` runs the
		// value through `htmlspecialchars()`, which turns every `"` into `&quot;` and makes valid
		// JSON undecodable. Safety comes from the byte ceiling, the depth limit and the schema
		// check below, not from escaping request data that is never rendered as HTML.
		$rawLayout = Minz_Request::paramString('layout', true);
		$tabId = Minz_Request::paramString('tab_id');
		if ($rawLayout === '' || $tabId === '') {
			$this->failWithBadRequest();
		}

		// Bound the payload before decoding it, so a huge body cannot be turned into a huge array.
		if (strlen($rawLayout) > LayoutSchema::MAX_LAYOUT_PAYLOAD_BYTES) {
			$this->failWithBadRequest();
		}

		$decoded = json_decode($rawLayout, true, 8);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->failWithBadRequest();
		}

		$layout = $this->getLayout();
		$targetIndex = null;
		foreach ($layout as $index => $tab) {
			if ($tab['id'] === $tabId) {
				$targetIndex = $index;
				break;
			}
		}
		// Report an unknown tab instead of accepting the request and saving nothing.
		if ($targetIndex === null) {
			$this->failWithBadRequest();
		}

		$numColumns = (int)($layout[$targetIndex]['num_columns'] ?? FreshVibesViewExtension::DEFAULT_TAB_COLUMNS);
		// A tab that already holds more feeds than the ceiling keeps its current size as the bound,
		// so reordering inside an oversized tab is possible instead of failing on every drag.
		$columns = LayoutSchema::validateColumns(
			$decoded,
			$this->subscribedFeedIds(),
			$numColumns,
			LayoutSchema::tabSize($layout, $tabId)
		);
		if ($columns === null) {
			$this->failWithBadRequest();
		}

		// Validate the complete new state before touching the stored one.
		$layout[$targetIndex]['columns'] = $columns;
		$this->saveLayout($layout);
		echo json_encode(['status' => 'success']);
		exit;
	}

	public function updateTabAction() {
		$this->validatePostRequest();
		header('Content-Type: application/json');
		if (!isset($_POST['operation'])) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_request')]);
			exit;
		}

		$operation = Minz_Request::paramString('operation');
		$layout = $this->getLayout();
		$mode = FreshRSS_Context::userConf()->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
		$keys = ConfigKeys::forMode($mode);

		try {
			switch ($operation) {
				case 'add':
					if ($mode === 'categories') {
						http_response_code(403);
						echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_operation_not_allowed')]);
						exit;
					}
					if (count($layout) >= LayoutSchema::MAX_TABS) {
						http_response_code(400);
						echo json_encode([
							'status' => 'error',
							'message' => _t('ext.FreshVibesView.error_too_many_tabs'),
						]);
						exit;
					}
					$newTab = [
						'id' => 'tab-' . microtime(true) . rand(),
						'name' => _t('ext.FreshVibesView.new_tab_name', 'New Tab'),
						'icon' => '',
						'icon_color' => '',
						'num_columns' => FreshVibesViewExtension::DEFAULT_TAB_COLUMNS,
						'columns' => $this->buildEmptyColumns(FreshVibesViewExtension::DEFAULT_TAB_COLUMNS),
					];
					$layout[] = $newTab;
					$this->saveLayout($layout);
					echo json_encode(['status' => 'success', 'new_tab' => $newTab]);
					break;
				case 'delete':
					if ($mode === 'categories') {
						http_response_code(403);
						echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_operation_not_allowed')]);
						exit;
					}
					if (count($layout) <= 1) {
						http_response_code(400);
						echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_delete_last_tab')]);
						exit;
					}
					$tabId = Minz_Request::paramString('tab_id');
					if (!LayoutSchema::tabExists($layout, $tabId)) {
						$this->failWithBadRequest();
					}
					$feedsToMove = [];
					$deletedTabIndex = -1;
					foreach ($layout as $index => $tab) {
						if ($tab['id'] === $tabId) {
							foreach ($tab['columns'] as $column) {
								$feedsToMove = array_merge($feedsToMove, $column);
							}
							$deletedTabIndex = $index;
							break;
						}
					}
					if ($deletedTabIndex !== -1) {
						unset($layout[$deletedTabIndex]);
						$layout = array_values($layout);
						// The tab's colours are stored under keys derived from its ID. Without this
						// they outlived every tab the user ever deleted, so bounding the number of
						// live tabs did not bound the stored configuration. `userConf()` returns the
						// request-wide instance, so `saveLayout()` below persists these removals too.
						$userConf = FreshRSS_Context::userConf();
						$userConf->_attribute($keys->tabBgColor($tabId), null);
						$userConf->_attribute($keys->tabFontColor($tabId), null);
						if (!empty($feedsToMove)) {
							// Ensure columns exist and get first column key safely
							if (!isset($layout[0]['columns']) || empty($layout[0]['columns'])) {
								$layout[0]['columns'] = ['col1' => []];
							}
							$firstColKey = key($layout[0]['columns']);
							if (!isset($layout[0]['columns'][$firstColKey])) {
								$layout[0]['columns'][$firstColKey] = [];
							}
							$layout[0]['columns'][$firstColKey] = array_unique(array_merge($layout[0]['columns'][$firstColKey], $feedsToMove));
						}
					}
					$this->saveLayout($layout);
					echo json_encode([
						'status' => 'success',
						'deleted_tab_id' => $tabId,
						'new_layout' => $this->layoutForResponse($layout),
					]);
					break;
				case 'rename':
					if ($mode === 'categories') {
						http_response_code(403);
						echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_operation_not_allowed')]);
						exit;
					}
					$tabId = Minz_Request::paramString('tab_id');
					if (!LayoutSchema::tabExists($layout, $tabId)) {
						$this->failWithBadRequest();
					}
					// Read unescaped so the limits apply to the text the user actually typed:
					// `paramString()` escapes by default, which made a name of four `<` count as
					// sixteen characters and hid control characters behind their entities.
					$newName = LayoutSchema::normalizeName(Minz_Request::paramString('value', true));
					if ($newName === null) {
						http_response_code(400);
						echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_tab_name_empty')]);
						exit;
					}
					// Stored escaped, because `getLayoutAction()` decodes names on the way out —
					// which is also how every name saved by an earlier release is stored. Escaping
					// here keeps that round-trip exact, so a name containing `&` or `<` survives it
					// unchanged instead of being decoded into something the user never typed.
					$storedName = htmlspecialchars($newName, ENT_QUOTES, 'UTF-8');
					foreach ($layout as &$tab) {
						if ($tab['id'] === $tabId) {
							$tab['name'] = $storedName;
							break;
						}
					}
					unset($tab);
					$this->saveLayout($layout);
					echo json_encode(['status' => 'success']);
					break;
				case 'set_columns':
					$tabId = Minz_Request::paramString('tab_id');
					if (!LayoutSchema::tabExists($layout, $tabId)) {
						$this->failWithBadRequest();
					}
					$numCols = Minz_Request::paramInt('value') ?: FreshVibesViewExtension::DEFAULT_TAB_COLUMNS;
					if ($numCols < 1 || $numCols > 6) {
						$numCols = FreshVibesViewExtension::DEFAULT_TAB_COLUMNS;
					}
					foreach ($layout as &$tab) {
						if ($tab['id'] === $tabId) {
							$tab['columns'] = LayoutSchema::redistributeColumns($tab, $numCols);
							$tab['num_columns'] = $numCols;
							break;
						}
					}
					unset($tab);
					$this->saveLayout($layout);
					echo json_encode(['status' => 'success', 'new_layout' => $this->layoutForResponse($layout)]);
					break;
				case 'set_icon':
					$tabId = Minz_Request::paramString('tab_id');
					if (!LayoutSchema::tabExists($layout, $tabId)) {
						$this->failWithBadRequest();
					}
					// Same reasoning as the tab name: the icon is a short piece of text (usually an
					// emoji) rendered with `textContent`, and escaping it would break both the value
					// and the length check.
					$icon = Minz_Request::paramString('icon', true);
					$color = Minz_Request::paramString('color');
					if (!mb_check_encoding($icon, 'UTF-8')
						|| mb_strlen($icon) > LayoutSchema::MAX_ICON_LENGTH
						|| preg_match('/[\x00-\x1F\x7F]/u', $icon) === 1
						|| ($color !== '' && !Sanitizer::isHexColor($color))) {
						$this->failWithBadRequest();
					}
					foreach ($layout as &$tab) {
						if ($tab['id'] === $tabId) {
							$tab['icon'] = $icon;
							$tab['icon_color'] = $color;
							break;
						}
					}
					$this->saveLayout($layout);
					echo json_encode(['status' => 'success']);
					break;
				case 'set_colors':
					$tabId = Minz_Request::paramString('tab_id');
					$bgColor = Minz_Request::paramString('bg_color');
					$fontColor = Minz_Request::paramString('font_color');
					// Reject non-hex input rather than letting it reach hexdec(), whose behaviour for
					// invalid characters has been deprecated since PHP 7.4.
					if (($bgColor !== '' && !Sanitizer::isHexColor($bgColor))
						|| ($fontColor !== '' && !Sanitizer::isHexColor($fontColor))) {
						$this->failWithBadRequest();
					}
					if (!LayoutSchema::tabExists($layout, $tabId)) {
						$this->failWithBadRequest();
					}
					if ($fontColor === '') {
						$fontColor = $bgColor !== '' ? $this->getContrastColor($bgColor) : '';
					}

					$userConf = FreshRSS_Context::userConf();
					$userConf->_attribute($keys->tabBgColor($tabId), $bgColor);
					$userConf->_attribute($keys->tabFontColor($tabId), $fontColor);
					if (!$userConf->save()) {
						$this->failWithPersistenceError('updateTabAction/set_colors');
					}

					echo json_encode(['status' => 'success', 'font_color' => $fontColor]);
					break;
				case 'reorder':
					if ($mode === 'categories') {
						http_response_code(403);
						echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_operation_not_allowed')]);
						exit;
					}
					// An exact permutation is required. A subset would silently delete the omitted
					// tabs, and an empty `tab_ids` used to pass the old `!empty()` check as [''],
					// wiping the entire layout.
					$newLayout = LayoutSchema::reorderTabs($layout, explode(',', Minz_Request::paramString('tab_ids')));
					if ($newLayout === null) {
						http_response_code(400);
						echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_tab_order')]);
						break;
					}
					$this->saveLayout($newLayout);
					echo json_encode(['status' => 'success']);
					break;
				default:
					http_response_code(400);
					echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_unknown_tab_operation')]);
					exit;
			}
		} catch (Exception $e) {
			http_response_code(500);
			error_log('FreshVibesView updateTabAction error: ' . $e->getMessage());
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_server')]);
		}
		exit;
	}

	/** @return array<string,list<int>> */
	private function buildEmptyColumns(int $count): array {
		return LayoutSchema::buildEmptyColumns($count);
	}

	public function setActiveTabAction() {
		$this->validatePostRequest();

		// Read through Minz_Request so an array or over-long value cannot reach the configuration,
		// and only accept a tab that actually exists in the current layout.
		$tabId = Minz_Request::paramString('tab_id');
		if ($tabId === '' || mb_strlen($tabId) > LayoutSchema::MAX_TAB_ID_LENGTH) {
			$this->failWithBadRequest();
		}

		try {
			if (!LayoutSchema::tabExists($this->getLayout(), $tabId)) {
				$this->failWithBadRequest();
			}

			$userConf = FreshRSS_Context::userConf();
			$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
			$keys = ConfigKeys::forMode($mode);
			$key = $keys->activeTab();
			$userConf->_attribute($key, $tabId);
			if (!$userConf->save()) {
				$this->failWithPersistenceError('setActiveTabAction');
			}
			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			$this->failWithGenericError('setActiveTabAction', $e);
		}
		exit;
	}

	public function saveFeedSettingsAction() {
		$this->validatePostRequest();
		if (!isset($_POST['feed_id'])) {
			http_response_code(400);
			exit;
		}
		$feedId = Minz_Request::paramInt('feed_id');
		$limit = Minz_Request::paramString('limit');
		$fontSize = Minz_Request::paramString('font_size');
		$maxHeight = Minz_Request::paramString('max_height');
		$displayMode = Minz_Request::paramString('display_mode');

		$limitForValidation = is_numeric($limit) ? (int)$limit : $limit;
		$isValidMaxHeight = in_array($maxHeight, ['unlimited', 'fit'], true)
			|| (is_numeric($maxHeight) && (int)$maxHeight >= 0 && (int)$maxHeight <= LayoutSchema::MAX_FEED_HEIGHT);

		if (is_numeric($maxHeight) && !$isValidMaxHeight) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.fv_invalid_height')]);
			exit;
		}

		if (
			$feedId <= 0 ||
			!in_array($feedId, $this->subscribedFeedIds(), true) ||
			!in_array($limitForValidation, FreshVibesViewExtension::ALLOWED_LIMIT_VALUES, true) ||
			!in_array($fontSize, FreshVibesViewExtension::ALLOWED_FONT_SIZES, true) ||
			!$isValidMaxHeight ||
			!in_array($displayMode, FreshVibesViewExtension::ALLOWED_DISPLAY_MODES, true)
		) {
			http_response_code(400);
			exit;
		}
		try {
			$userConf = FreshRSS_Context::userConf();
			$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
			$keys = ConfigKeys::forMode($mode);

			$userConf->_attribute($keys->limit($feedId), $limitForValidation);
			$userConf->_attribute($keys->fontSize($feedId), $fontSize);
			$userConf->_attribute($keys->maxHeight($feedId), $maxHeight);
			$userConf->_attribute($keys->displayMode($feedId), $displayMode);

			// Only update header color if it was provided in the request
			if (isset($_POST['header_color'])) {
				$headerColor = Minz_Request::paramString('header_color');
				if ($headerColor !== '' && !Sanitizer::isHexColor($headerColor)) {
					http_response_code(400);
					echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_request')]);
					exit;
				}
				if ($headerColor === '') {
					$userConf->_attribute($keys->headerColor($feedId), null);
				} else {
					$userConf->_attribute($keys->headerColor($feedId), $headerColor);
				}
			}

			if (!$userConf->save()) {
				$this->failWithPersistenceError('saveFeedSettingsAction');
			}
			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			http_response_code(500);
			error_log('FreshVibesView saveFeedSettingsAction error: ' . $e->getMessage());
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_server')]);
		}
		exit;
	}

	public function moveFeedAction() {
		$this->validatePostRequest();
		header('Content-Type: application/json');

		$feedId = Minz_Request::paramInt('feed_id');
		$targetTabId = Minz_Request::paramString('target_tab_id');

		if ($feedId <= 0 || $targetTabId === '') {
			$this->failWithBadRequest();
		}

		$layout = $this->getLayout();

		// Validate the destination and the feed *before* removing anything. Previously an unknown
		// target still stripped the feed from every tab and returned success, losing its placement.
		if (!LayoutSchema::tabExists($layout, $targetTabId)) {
			$this->failWithBadRequest();
		}
		if (!in_array($feedId, $this->subscribedFeedIds(), true)) {
			$this->failWithBadRequest();
		}

		// Globally remove the feed from all tabs to prevent duplicates
		foreach ($layout as &$tab) {
			if (isset($tab['columns']) && is_array($tab['columns'])) {
				foreach ($tab['columns'] as &$column) {
					if (is_array($column)) {
						$column = array_values(array_filter($column, function ($id) use ($feedId) {
							return intval($id) !== $feedId;
						}));
					}
				}
				unset($column);
			}
		}
		unset($tab);

		// Add the feed to the target tab
		foreach ($layout as &$tab) {
			if ($tab['id'] === $targetTabId) {
				$firstColKey = !empty($tab['columns']) ? key($tab['columns']) : 'col1';
				if (!isset($tab['columns'][$firstColKey])) {
					$tab['columns'][$firstColKey] = [];
				}
				// Add feed to the beginning of the first column
				array_unshift($tab['columns'][$firstColKey], $feedId);
				break;
			}
		}
		unset($tab);

		$this->saveLayout($layout);
		echo json_encode(['status' => 'success', 'new_layout' => $this->layoutForResponse($layout)]);
		exit;
	}

	/**
	 * Build the JSON payload for one entry.
	 *
	 * Shared by the initial page render so the same normalisation applies everywhere. Content is
	 * processed once and every snippet is derived from that single representation.
	 *
	 * @return array<string,mixed>
	 */
	private function serializeEntry(FreshRSS_Entry $entry, int $feedId, string $dateFormat): array {
		$content = $entry->content();
		// FreshRSS stores content with the XML-sensitive characters still entity-encoded. Plain
		// text is produced by removing tags and only then decoding, so text a feed escaped stays
		// text; the rich excerpt keeps markup and is filtered against an explicit allow-list.
		$plainText = Sanitizer::toText($content);

		return [
			'id' => $entry->id(),
			'link' => Sanitizer::safeUrl($entry->link()),
			'title' => html_entity_decode($entry->title(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			'dateShort' => date($dateFormat, $entry->date(true)),
			'dateRelative' => $this->getRelativeDate($entry->date(true)),
			'dateFull' => (string) $entry->date(true),
			// Tiny and compact views render these with textContent.
			'snippet' => Sanitizer::truncateWords($plainText, 15),
			'compactSnippet' => Sanitizer::truncateWords($plainText, 30),
			// Allow-listed markup for the modal only.
			'detailedSnippet' => Sanitizer::sanitizeHtml($content, 100),
			// Plain-text counterpart, so the list view and tooltips never parse HTML in the browser.
			'detailedText' => Sanitizer::truncateSentences($plainText, 3),
			'isRead' => $entry->isRead() ?? false,
			'isFavorite' => $entry->isFavorite(),
			'author' => html_entity_decode($entry->authors(asString: true), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			'tags' => $entry->tags(),
			'feedId' => $feedId,
		];
	}

	/** The configured hard ceiling on entries fetched per feed. */
	private function entriesCap(FreshRSS_UserConfiguration $userConf): int {
		$cap = $userConf->attributeInt(FreshVibesViewExtension::MAX_ENTRIES_CAP_CONFIG_KEY)
			?: FreshVibesViewExtension::DEFAULT_MAX_ENTRIES_CAP;
		return max(
			FreshVibesViewExtension::MIN_MAX_ENTRIES_CAP,
			min(FreshVibesViewExtension::MAX_MAX_ENTRIES_CAP, $cap)
		);
	}

	/**
	 * Translate a stored per-feed limit into the value passed to the DAO.
	 *
	 * `EntryDAO::listWhere()` only emits a SQL LIMIT for values greater than zero, so `unlimited`
	 * must be resolved to the cap rather than to 0. The cap is applied to numeric limits too, so a
	 * stale configuration can never exceed it.
	 *
	 * @param int|string $limit
	 */
	private function resolveQueryLimit($limit, int $cap): int {
		if ($limit === 'unlimited') {
			return $cap;
		}
		return max(1, min($cap, (int)$limit));
	}

	public function markFeedReadAction() {
		$this->validatePostRequest();
		header('Content-Type: application/json');

		if (!isset($_POST['feed_id'])) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_request')]);
			exit;
		}

		$feedId = Minz_Request::paramInt('feed_id');
		if ($feedId <= 0) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_request')]);
			exit;
		}

		$entryDAO = FreshRSS_Factory::createEntryDao();
		$idMax = uTimeString(); // Current timestamp
		$affected = $entryDAO->markReadFeed($feedId, $idMax);

		if ($affected !== false) {
			echo json_encode(['status' => 'success', 'affected' => $affected]);
		} else {
			http_response_code(500);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_mark_feed_read')]);
		}
		exit;
	}

	private function getContrastColor(string $hexColor): string {
		// hexdec() has emitted a deprecation notice for invalid characters since PHP 7.4, so the
		// value is validated here as well as at each call site.
		if (!Sanitizer::isHexColor($hexColor)) {
			return '#000000';
		}
		$hexColor = ltrim($hexColor, '#');
		$r = hexdec(substr($hexColor, 0, 2));
		$g = hexdec(substr($hexColor, 2, 2));
		$b = hexdec(substr($hexColor, 4, 2));

		// Calculate luminance
		$luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

		return $luminance > 0.5 ? '#000000' : '#ffffff';
	}

	public function markTabReadAction() {
		$this->validatePostRequest();
		header('Content-Type: application/json');

		if (!isset($_POST['tab_id'])) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_request')]);
			exit;
		}

		$tabId = Minz_Request::paramString('tab_id');

		try {
			$layout = $this->getLayout();
			$entryDAO = FreshRSS_Factory::createEntryDao();
			$idMax = uTimeString();
			$totalAffected = 0;
			$failedFeeds = 0;

			foreach ($layout as $tab) {
				if ($tab['id'] === $tabId) {
					foreach ($tab['columns'] as $column) {
						foreach ($column as $feedId) {
							$feedIdInt = intval($feedId);
							if ($feedIdInt > 0) {
								$affected = $entryDAO->markReadFeed($feedIdInt, $idMax);
								if ($affected === false) {
									// `markReadFeed()` reports failure by return value. Counting the
									// failures keeps the response honest: the operation spans many
									// feeds and is not transactional, so it can genuinely be partial.
									$failedFeeds++;
								} else {
									$totalAffected += $affected;
								}
							}
						}
					}
					break;
				}
			}

			if ($failedFeeds > 0) {
				Minz_Log::error('FreshVibesView markTabReadAction: ' . $failedFeeds . ' feed(s) could not be marked read.');
				http_response_code(500);
				echo json_encode([
					'status' => 'error',
					'message' => _t('ext.FreshVibesView.error_mark_feed_read'),
					'affected' => $totalAffected,
				]);
				exit;
			}

			echo json_encode(['status' => 'success', 'affected' => $totalAffected]);
		} catch (Exception $e) {
			$this->failWithGenericError('markTabReadAction', $e);
		}
		exit;
	}

	private function getRelativeDate(int $timestamp): string {
		$diff = time() - $timestamp;

		// Feeds may carry future publication dates, which FreshRSS stores verbatim. Only treat the
		// last minute as "now"; anything further ahead falls through to the absolute date.
		if ($diff < 0) {
			$dateFormat = FreshRSS_Context::userConf()->attributeString(FreshVibesViewExtension::DATE_FORMAT_CONFIG_KEY)
				?: FreshVibesViewExtension::DEFAULT_DATE_FORMAT;
			return date($dateFormat, $timestamp);
		}

		if ($diff < 60) {
			return _t('ext.FreshVibesView.date_relative_now');
		}

		$minutes = round($diff / 60);
		if ($minutes < 60) {
			if ($minutes == 1) {
				return _t('ext.FreshVibesView.date_relative_minute_ago');
			}
			return sprintf(_t('ext.FreshVibesView.date_relative_minutes_ago'), $minutes);
		}

		$hours = round($diff / 3600);
		if ($hours < 24) {
			if ($hours == 1) {
				return _t('ext.FreshVibesView.date_relative_hour_ago');
			}
			return sprintf(_t('ext.FreshVibesView.date_relative_hours_ago'), $hours);
		}

		$days = round($diff / 86400);
		if ($days < 7) {
			if ($days == 1) {
				return _t('ext.FreshVibesView.date_relative_day_ago');
			}
			return sprintf(_t('ext.FreshVibesView.date_relative_days_ago'), $days);
		}

		$weeks = round($diff / 604800);
		if ($weeks < 4.345) { // Average weeks in a month
			if ($weeks == 1) {
				return _t('ext.FreshVibesView.date_relative_week_ago');
			}
			return sprintf(_t('ext.FreshVibesView.date_relative_weeks_ago'), $weeks);
		}

		$months = round($diff / 2600640); // Avg seconds in a month
		if ($months < 12) {
			if ($months == 1) {
				return _t('ext.FreshVibesView.date_relative_month_ago');
			}
			return sprintf(_t('ext.FreshVibesView.date_relative_months_ago'), $months);
		}

		$years = round($diff / 31207680); // Avg seconds in a year
		if ($years == 1) {
			return _t('ext.FreshVibesView.date_relative_year_ago');
		}
		return sprintf(_t('ext.FreshVibesView.date_relative_years_ago'), $years);
	}

	public function bulkApplyFeedSettingsAction() {
		$this->validatePostRequest();
		header('Content-Type: application/json');

		$limit = Minz_Request::paramString('limit');
		$fontSize = Minz_Request::paramString('font_size');
		$headerColor = Minz_Request::paramStringNull('header_color');
		$maxHeight = Minz_Request::paramString('max_height');
		$displayMode = Minz_Request::paramString('display_mode');

		$limitForValidation = is_numeric($limit) ? (int)$limit : $limit;
		// Match the per-feed endpoint: heights are bounded and colours must be six-digit hex.
		$isValidMaxHeight = in_array($maxHeight, ['unlimited', 'fit'], true)
			|| (is_numeric($maxHeight) && (int)$maxHeight >= 0 && (int)$maxHeight <= LayoutSchema::MAX_FEED_HEIGHT);

		if (
			!in_array($limitForValidation, FreshVibesViewExtension::ALLOWED_LIMIT_VALUES, true) ||
			!in_array($fontSize, FreshVibesViewExtension::ALLOWED_FONT_SIZES, true) ||
			!$isValidMaxHeight ||
			($headerColor !== null && $headerColor !== '' && !Sanitizer::isHexColor($headerColor)) ||
			!in_array($displayMode, FreshVibesViewExtension::ALLOWED_DISPLAY_MODES, true)
		) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_settings')]);
			exit;
		}

		try {
			$userConf = FreshRSS_Context::userConf();
			$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
			$keys = ConfigKeys::forMode($mode);
			$feedDAO = FreshRSS_Factory::createFeedDao();
			$feeds = $feedDAO->listFeeds();

			foreach ($feeds as $feed) {
				$feedId = $feed->id();

				$userConf->_attribute($keys->limit($feedId), $limitForValidation);
				$userConf->_attribute($keys->fontSize($feedId), $fontSize);
				$userConf->_attribute($keys->maxHeight($feedId), $maxHeight);
				$userConf->_attribute($keys->displayMode($feedId), $displayMode);

				if ($headerColor !== null) {
					if ($headerColor === '') {
						$userConf->_attribute($keys->headerColor($feedId), null);
					} else {
						$userConf->_attribute($keys->headerColor($feedId), $headerColor);
					}
				}
			}

			if (!$userConf->save()) {
				$this->failWithPersistenceError('bulkApplyFeedSettingsAction');
			}
			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			$this->failWithGenericError('bulkApplyFeedSettingsAction', $e);
		}
		exit;
	}

	public function bulkApplyTabSettingsAction() {
		$this->validatePostRequest();
		header('Content-Type: application/json');

		$numColumns = Minz_Request::paramInt('num_columns') ?: FreshVibesViewExtension::DEFAULT_TAB_COLUMNS;
		$bgColor = Minz_Request::paramString('bg_color');
		$fontColor = Minz_Request::paramString('font_color');

		if ($numColumns < LayoutSchema::MIN_COLUMNS || $numColumns > LayoutSchema::MAX_COLUMNS) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_settings')]);
			exit;
		}
		if (($bgColor !== '' && !Sanitizer::isHexColor($bgColor))
			|| ($fontColor !== '' && !Sanitizer::isHexColor($fontColor))) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_settings')]);
			exit;
		}

		try {
			$layout = $this->getLayout();
			$userConf = FreshRSS_Context::userConf();
			$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
			$keys = ConfigKeys::forMode($mode);

			foreach ($layout as &$tab) {
				$tab['columns'] = LayoutSchema::redistributeColumns($tab, $numColumns);
				$tab['num_columns'] = $numColumns;

				if ($bgColor != '') {
					$userConf->_attribute($keys->tabBgColor($tab['id']), $bgColor);
					$actualFontColor = $fontColor ?: $this->getContrastColor($bgColor);
					$userConf->_attribute($keys->tabFontColor($tab['id']), $actualFontColor);
				}
			}

			// `saveLayout()` writes the request-wide configuration instance, so the colour
			// attributes set above are persisted by that same call.
			$this->saveLayout($layout);

			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			$this->failWithGenericError('bulkApplyTabSettingsAction', $e);
		}
		exit;
	}

	public function resetAllFeedSettingsAction() {
		$this->validatePostRequest();
		header('Content-Type: application/json');

		try {
			$userConf = FreshRSS_Context::userConf();
			$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
			$keys = ConfigKeys::forMode($mode);
			$feedDAO = FreshRSS_Factory::createFeedDao();
			$feeds = $feedDAO->listFeeds();

			foreach ($feeds as $feed) {
				// Keep the resolver in `$keys`. Overwriting it with the key list turned the second
				// iteration into `array->limit()`, which raises an `Error` rather than an
				// `Exception`, so nothing was caught and nothing was ever saved.
				foreach ($keys->allFeedKeys($feed->id()) as $key) {
					// Assigning null removes the key, which is what "reset to default" means here:
					// the next dashboard read seeds the declared defaults again.
					$userConf->_attribute($key, null);
				}
			}

			if (!$userConf->save()) {
				$this->failWithPersistenceError('resetAllFeedSettingsAction');
			}
			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			$this->failWithGenericError('resetAllFeedSettingsAction', $e);
		}
		exit;
	}

	public function resetAllTabSettingsAction() {
		$this->validatePostRequest();
		header('Content-Type: application/json');

		try {
			$layout = $this->getLayout();
			$userConf = FreshRSS_Context::userConf();
			$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
			$keys = ConfigKeys::forMode($mode);

			foreach ($layout as &$tab) {
				$tab['columns'] = LayoutSchema::redistributeColumns($tab, FreshVibesViewExtension::DEFAULT_TAB_COLUMNS);
				$tab['num_columns'] = FreshVibesViewExtension::DEFAULT_TAB_COLUMNS;
				$tab['icon'] = '';
				$tab['icon_color'] = '';

				$userConf->_attribute($keys->tabBgColor($tab['id']), null);
				$userConf->_attribute($keys->tabFontColor($tab['id']), null);
			}

			// As above: the colour removals ride along with the layout write.
			$this->saveLayout($layout);

			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			$this->failWithGenericError('resetAllTabSettingsAction', $e);
		}
		exit;
	}

	private function noCacheHeaders() {
		header('Cache-Control: no-cache, no-store, must-revalidate');
		header('Pragma: no-cache');
		header('Expires: 0');
	}

	/**
	 * Gate for every state-changing action: POST, authenticated, valid CSRF token.
	 *
	 * FreshRSS validates CSRF globally for POST requests, but the extension states its own policy
	 * explicitly rather than relying on another layer — the same thing FreshRSS's own entry and
	 * category controllers do in their `firstAction()`.
	 */
	private function validatePostRequest(): void {
		$this->noCacheHeaders();
		header('Content-Type: application/json');

		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			http_response_code(405);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_request')]);
			exit;
		}

		if (!FreshRSS_Auth::hasAccess()) {
			http_response_code(403);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_access_denied')]);
			exit;
		}

		if (!FreshRSS_Auth::isCsrfOk()) {
			http_response_code(403);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.csrf_error')]);
			exit;
		}
	}

	/**
	 * Log the detail server-side and return a generic message.
	 *
	 * Driver and filesystem exceptions can carry table names, paths and query fragments, so their
	 * text must never reach the client.
	 */
	private function failWithGenericError(string $context, Exception $e): void {
		http_response_code(500);
		error_log('FreshVibesView ' . $context . ' error: ' . $e->getMessage());
		echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_server')]);
	}

	/**
	 * Respond with 500 when storage rejected a write.
	 *
	 * `Minz_Configuration::save()` and the DAOs report failure by return value, not by throwing, so
	 * a `try`/`catch` alone lets a failed write reach the client as a success. Answering the actual
	 * outcome keeps the UI from acknowledging settings that were never stored.
	 */
	private function failWithPersistenceError(string $context): void {
		http_response_code(500);
		Minz_Log::error('FreshVibesView ' . $context . ': persisting the change failed.');
		echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_server')]);
		exit;
	}

	/** Respond with 400 and a generic message. */
	private function failWithBadRequest(?string $message = null): void {
		http_response_code(400);
		echo json_encode([
			'status' => 'error',
			'message' => $message ?? _t('ext.FreshVibesView.error_invalid_request'),
		]);
		exit;
	}

	/**
	 * Feed IDs the current user is subscribed to.
	 *
	 * Memoised for the request: several actions ask for this two or three times, and layout
	 * reconciliation now needs it on every read as well. The controller instance does not outlive
	 * the request, so the subscription list cannot change underneath it.
	 *
	 * @var list<int>|null
	 */
	private ?array $subscribedFeedIds = null;

	/** @return list<int> */
	private function subscribedFeedIds(): array {
		if ($this->subscribedFeedIds === null) {
			$ids = [];
			foreach (FreshRSS_Factory::createFeedDao()->listFeeds() as $feed) {
				$ids[] = $feed->id();
			}
			$this->subscribedFeedIds = $ids;
		}
		return $this->subscribedFeedIds;
	}

	private function deduplicateLayout(array &$layout): void {
		LayoutSchema::deduplicate($layout);
	}

	public function saveCategoryOrderAction() {
		$this->validatePostRequest();

		$userConf = FreshRSS_Context::userConf();
		$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';

		// Reordering categories is only meaningful in category mode, and only when the user has
		// enabled it. Neither condition was checked before.
		if ($mode !== 'categories' || $userConf->attributeBool(FreshVibesViewExtension::ALLOW_CATEGORY_SORT_CONFIG_KEY) !== true) {
			http_response_code(403);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_operation_not_allowed')]);
			exit;
		}

		$rawIds = Minz_Request::paramString('category_ids');
		if ($rawIds === '') {
			$this->failWithBadRequest();
		}

		try {
			$categoryDAO = FreshRSS_Factory::createCategoryDao();

			// Fetch once, then require an exact permutation. A subset used to leave the omitted
			// categories on their previous positions, colliding with the reassigned ones, and an
			// empty value arrived as [''], matched nothing and still reported success.
			$categories = [];
			foreach ($categoryDAO->listCategories(false, false) as $category) {
				$categories[$category->id()] = $category;
			}

			$ordered = LayoutSchema::orderedCategoryIds(explode(',', $rawIds), array_keys($categories));
			if ($ordered === null) {
				$this->failWithBadRequest();
			}

			// The reorder is several row updates with no transaction around them, so a failure
			// halfway through leaves the categories in a mixed order. Stop at the first failure and
			// report it instead of finishing the loop and claiming success.
			$position = 1;
			foreach ($ordered as $catId) {
				$category = $categories[$catId];
				$category->_attribute('position', $position);
				$updated = $categoryDAO->updateCategory($catId, [
					'name' => $category->name(),
					'kind' => $category->kind(),
					'attributes' => $category->attributes(),
				]);
				if ($updated === false) {
					$this->failWithPersistenceError('saveCategoryOrderAction');
				}
				$position++;
			}

			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			$this->failWithGenericError('saveCategoryOrderAction', $e);
		}
		exit;
	}
}
