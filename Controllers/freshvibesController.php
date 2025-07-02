<?php

declare(strict_types=1);

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
		$currentState = FreshRSS_Context::$state;
		$feedsData = [];
		$dateFormat = $userConf->attributeString(FreshVibesViewExtension::DATE_FORMAT_CONFIG_KEY);

		foreach ($feeds as $feed) {
			$feedId = $feed->id();
			$limitKey = ($mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_LIMIT_CONFIG_PREFIX :
				FreshVibesViewExtension::LIMIT_CONFIG_PREFIX) .
				$feedId;
			$fontSizeKey = ($mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_FONT_SIZE_CONFIG_PREFIX :
				FreshVibesViewExtension::FONT_SIZE_CONFIG_PREFIX) .
				$feedId;

			$limit = $userConf->attributeInt($limitKey) ?? $userConf->attributeString($limitKey);
			$limit = is_numeric($limit) ? (int)$limit : $limit;
			if (!in_array($limit, FreshVibesViewExtension::ALLOWED_LIMIT_VALUES, true)) {
				$limit = FreshVibesViewExtension::DEFAULT_ARTICLES_PER_FEED;
			}
			$queryLimit = ($limit === 'unlimited') ? null : $limit;

			$fontSize = $userConf->attributeString($fontSizeKey);
			if (!in_array($fontSize, FreshVibesViewExtension::ALLOWED_FONT_SIZES, true)) {
				$fontSize = FreshVibesViewExtension::DEFAULT_FONT_SIZE;
			}

			$maxHeightKey = ($mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_MAX_HEIGHT_CONFIG_KEY :
				FreshVibesViewExtension::MAX_HEIGHT_CONFIG_KEY) .
				$feedId;
			$maxHeight = $userConf->attributeString($maxHeightKey);

			if (!in_array($maxHeight, FreshVibesViewExtension::ALLOWED_MAX_HEIGHTS_CONFIG_KEY, true) && !is_numeric($maxHeight)) {
				$maxHeight = FreshVibesViewExtension::DEFAULT_MAX_HEIGHT_CONFIG_KEY;
			}

			$headerColorKey = ($mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_FEED_HEADER_COLOR_CONFIG_PREFIX :
				FreshVibesViewExtension::FEED_HEADER_COLOR_CONFIG_PREFIX) .
				$feedId;
			$headerColor = $userConf->hasParam($headerColorKey) ? $userConf->attributeString($headerColorKey) : '';

			$displayModeKey = ($mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_FEED_DISPLAY_MODE_CONFIG_PREFIX :
				FreshVibesViewExtension::FEED_DISPLAY_MODE_CONFIG_PREFIX) .
				$feedId;
			$displayMode = $userConf->attributeString($displayModeKey);

			if (!in_array($displayMode, FreshVibesViewExtension::ALLOWED_DISPLAY_MODES, true)) {
				$displayMode = FreshVibesViewExtension::DEFAULT_DISPLAY_MODE;
			}

			try {
				// Get sorting from FreshRSS context
				$sort = FreshRSS_Context::$sort ?? 'date';
				$order = FreshRSS_Context::$order ?? 'DESC';

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
					continuation_value: 0,
					limit: $queryLimit ?? 0,
					offset: 0
				);
				$entries = [];

				foreach ($entryGenerator as $entry) {
					if ($entry instanceof FreshRSS_Entry) {
						$entries[] = [
							'id' => $entry->id(),
							'link' => $entry->link(),
							'title' => html_entity_decode($entry->title(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
							'dateShort' => date($dateFormat, $entry->date(true)),
							'dateRelative' => $this->getRelativeDate($entry->date(true)),
							'dateFull' => (string) $entry->date(true),
							'snippet' => $this->generateSnippet($entry, 15, 1), // tiny view
							'compactSnippet' => $this->generateSnippet($entry, 30, 1), // compact view
							'detailedSnippet' => $this->generateSnippet($entry, 100, 3), // detailed view with 3 sentences
							'isRead' => $entry->isRead() ?? false,
							'isFavorite' => $entry->isFavorite(),
							'author' => html_entity_decode($entry->authors(asString: true), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
							'tags' => $entry->tags(),
							'feedId' => $feedId,
						];
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
				'website' => $feed->website(),
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
		$this->view->refreshFeedsUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'refreshfeeds'], 'json', false);
		$this->view->feedSettingsUrl = Minz_Url::display() . '?c=subscription&a=feed&id=';
		$this->view->categorySettingsUrl = Minz_Url::display() . '?c=category&a=update&id=';

		$tags = FreshRSS_Context::labels(true);
		$this->view->tags = $tags;
		$nbUnreadTags = 0;
		foreach ($tags as $tag) {
			$nbUnreadTags += $tag->nbUnread();
		}
		$this->view->nbUnreadTags = $nbUnreadTags;

		$this->view->_path(FreshVibesViewExtension::CONTROLLER_NAME_BASE . '/index.phtml');
	}

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
			FreshVibesViewExtension::LAYOUT_CONFIG_KEY => null,
			FreshVibesViewExtension::CATEGORY_LAYOUT_CONFIG_KEY => null,
			FreshVibesViewExtension::MODE_CONFIG_KEY => 'custom',
		];

		foreach ($defaults as $key => $value) {
			$storedValue = $userConf->param($key);

			// Condition 1: Key is missing or its value is null. Set the default.
			if (!$userConf->hasParam($key) || $storedValue === null) {
				$userConf->_attribute($key, $value);
				$configChanged = true;
			}

			// Condition 2: It's a boolean setting, but the stored type is wrong (e.g. int).
			// Coerce the existing value to a boolean to migrate it.
			elseif (is_bool($value) && !is_bool($storedValue)) {
				$userConf->_attribute($key, (bool)$storedValue);
				$configChanged = true;
			}
		}

		$feedDAO = FreshRSS_Factory::createFeedDao();
		$feeds = $feedDAO->listFeeds();
		$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';

		foreach ($feeds as $feed) {
			$feedId = $feed->id();
			$feedDefaults = [
				($mode === 'categories' ? FreshVibesViewExtension::CATEGORY_LIMIT_CONFIG_PREFIX : FreshVibesViewExtension::LIMIT_CONFIG_PREFIX) . $feedId => FreshVibesViewExtension::DEFAULT_ARTICLES_PER_FEED,
				($mode === 'categories' ? FreshVibesViewExtension::CATEGORY_FONT_SIZE_CONFIG_PREFIX : FreshVibesViewExtension::FONT_SIZE_CONFIG_PREFIX) . $feedId => FreshVibesViewExtension::DEFAULT_FONT_SIZE,
				($mode === 'categories' ? FreshVibesViewExtension::CATEGORY_MAX_HEIGHT_CONFIG_KEY : FreshVibesViewExtension::MAX_HEIGHT_CONFIG_KEY) . $feedId => FreshVibesViewExtension::DEFAULT_MAX_HEIGHT_CONFIG_KEY,
				($mode === 'categories' ? FreshVibesViewExtension::CATEGORY_FEED_DISPLAY_MODE_CONFIG_PREFIX : FreshVibesViewExtension::FEED_DISPLAY_MODE_CONFIG_PREFIX) . $feedId => FreshVibesViewExtension::DEFAULT_DISPLAY_MODE,
			];

			foreach ($feedDefaults as $key => $value) {
				if (!$userConf->hasParam($key) || $userConf->param($key) === null) {
					$userConf->_attribute($key, $value);
					$configChanged = true;
				}
			}
		}

		if ($configChanged) {
			$userConf->save();
		}
	}

	private function getLayout(): array {
		$userConf = FreshRSS_Context::userConf();
		$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
		$layoutKey = $mode === 'categories'
			? FreshVibesViewExtension::CATEGORY_LAYOUT_CONFIG_KEY
			: FreshVibesViewExtension::LAYOUT_CONFIG_KEY;
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
						$colFeeds = array_values(array_intersect($colFeeds, $feedIds));
					}
					unset($colFeeds);

					// Add any missing feeds from this category
					$existingFeedIds = [];
					foreach ($columns as $colFeeds) {
						if (is_array($colFeeds)) {
							$existingFeedIds = array_merge($existingFeedIds, $colFeeds);
						}
					}
					$missingFeeds = array_diff($feedIds, $existingFeedIds);

					if (!empty($missingFeeds)) {
						if ($newFeedPosition === 'top') {
							// Add new feeds at the top of the first column
							$columns['col1'] = array_merge(array_values($missingFeeds), $columns['col1']);
						} else {
							// Add at the bottom (existing behavior)
							$numCols = $existingTab['num_columns'];
							$i = count($existingFeedIds);
							foreach ($missingFeeds as $feedId) {
								$colKey = 'col' . (($i % $numCols) + 1);
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

			$this->saveLayout($layout);
			return $layout;
		}

		if ($layout === null) {
			$numCols = FreshVibesViewExtension::DEFAULT_TAB_COLUMNS;
			$feedDAO = FreshRSS_Factory::createFeedDao();

			$columns = $this->buildEmptyColumns($numCols);
			$feeds = $feedDAO->listFeeds();

			if ($newFeedPosition === 'top') {
				// Add all feeds to the first column
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

			$layout = [[
				'id' => 'tab-' . microtime(true),
				'name' => _t('ext.FreshVibesView.default_tab_name', 'Main'),
				'icon' => '',
				'icon_color' => '',
				'num_columns' => $numCols,
				'columns' => $columns,
			]];

			$this->saveLayout($layout);
		}

		// Ensure all layout columns are arrays before returning
		foreach ($layout as &$tab) {
			if (isset($tab['columns']) && is_array($tab['columns'])) {
				foreach ($tab['columns'] as &$column) {
					if (!is_array($column)) {
						$column = [];
					}
				}
			}
		}

		return $layout;
	}

	private function saveLayout(array $layout): void {
		$userConf = FreshRSS_Context::userConf();
		$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
		$layoutKey = $mode === 'categories'
			? FreshVibesViewExtension::CATEGORY_LAYOUT_CONFIG_KEY
			: FreshVibesViewExtension::LAYOUT_CONFIG_KEY;
		$userConf->_attribute($layoutKey, $layout);
		$userConf->save();
	}


	public function refreshfeedsAction() {
		header('Content-Type: application/json');

		$feedDAO = FreshRSS_Factory::createFeedDao();
		$entryDAO = FreshRSS_Factory::createEntryDao();
		$userConf = FreshRSS_Context::userConf();
		$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
		$currentState = FreshRSS_Context::$state;
		$feedsData = [];
		$dateFormat = $userConf->attributeString(FreshVibesViewExtension::DATE_FORMAT_CONFIG_KEY) ?? 'Y-m-d H:i';
		$feeds = $feedDAO->listFeeds();

		foreach ($feeds as $feed) {
			$feedId = $feed->id();

			// Get all feed settings - this was missing!
			$limitKey = ($mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_LIMIT_CONFIG_PREFIX :
				FreshVibesViewExtension::LIMIT_CONFIG_PREFIX) .
				$feedId;
			$fontSizeKey = ($mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_FONT_SIZE_CONFIG_PREFIX :
				FreshVibesViewExtension::FONT_SIZE_CONFIG_PREFIX) .
				$feedId;
			$headerColorKey = ($mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_FEED_HEADER_COLOR_CONFIG_PREFIX :
				FreshVibesViewExtension::FEED_HEADER_COLOR_CONFIG_PREFIX) .
				$feedId;
			$maxHeightKey = ($mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_MAX_HEIGHT_CONFIG_KEY :
				FreshVibesViewExtension::MAX_HEIGHT_CONFIG_KEY) .
				$feedId;
			$displayModeKey = ($mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_FEED_DISPLAY_MODE_CONFIG_PREFIX :
				FreshVibesViewExtension::FEED_DISPLAY_MODE_CONFIG_PREFIX) .
				$feedId;

			// Get limit with validation
			$limit = $userConf->attributeInt($limitKey) ?? $userConf->attributeString($limitKey) ?? FreshVibesViewExtension::DEFAULT_ARTICLES_PER_FEED;
			$limitForValidation = $limit;
			if (!in_array($limitForValidation, FreshVibesViewExtension::ALLOWED_LIMIT_VALUES, true)) {
				$limit = FreshVibesViewExtension::DEFAULT_ARTICLES_PER_FEED;
			}
			$queryLimit = ($limit === 'unlimited') ? null : (int)$limit;

			// Get font size
			$fontSize = $userConf->attributeString($fontSizeKey) ?? FreshVibesViewExtension::DEFAULT_FONT_SIZE;
			if (!in_array($fontSize, FreshVibesViewExtension::ALLOWED_FONT_SIZES, true)) {
				$fontSize = FreshVibesViewExtension::DEFAULT_FONT_SIZE;
			}

			// Get header color
			if ($userConf->hasParam($headerColorKey)) {
				$headerColor = $userConf->attributeString($headerColorKey);
			} else {
				$headerColor = '';
			}

			// Get max height
			if ($userConf->hasParam($maxHeightKey)) {
				$maxHeight = $userConf->attributeString($maxHeightKey);
			} else {
				$maxHeight = FreshVibesViewExtension::DEFAULT_MAX_HEIGHT_CONFIG_KEY;
			}
			if (!in_array($maxHeight, FreshVibesViewExtension::ALLOWED_MAX_HEIGHTS_CONFIG_KEY, true)) {
				$maxHeight = FreshVibesViewExtension::DEFAULT_MAX_HEIGHT_CONFIG_KEY;
			}

			// Get display mode
			if ($userConf->hasParam($displayModeKey)) {
				$displayMode = $userConf->attributeString($displayModeKey);
			} else {
				$displayMode = FreshVibesViewExtension::DEFAULT_DISPLAY_MODE;
			}
			if (!in_array($displayMode, FreshVibesViewExtension::ALLOWED_DISPLAY_MODES, true)) {
				$displayMode = FreshVibesViewExtension::DEFAULT_DISPLAY_MODE;
			}

			// Get entries
			$entryGenerator = $entryDAO->listWhere(
				'f',
				$feedId,
				$currentState,
				null,
				'0',
				'0',
				(FreshRSS_Context::$sort ?? 'date'),
				(FreshRSS_Context::$order ?? 'DESC'),
				'0',
				0,
				$queryLimit ?? 0,
				0
			);

			$entries = [];
			foreach ($entryGenerator as $entry) {
				if ($entry instanceof FreshRSS_Entry) {
					$entries[] = [
						'id' => $entry->id(),
						'link' => $entry->link(),
						'title' => html_entity_decode($entry->title(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
						'dateShort' => date($dateFormat, $entry->date(true)),
						'dateRelative' => $this->getRelativeDate($entry->date(true)),
						'dateFull' => (string) $entry->date(true),
						'snippet' => $this->generateSnippet($entry, 15, 1),
						'compactSnippet' => $this->generateSnippet($entry, 30, 1),
						'detailedSnippet' => $this->generateSnippet($entry, 100, 3),
						'isRead' => $entry->isRead(),
						'isFavorite' => $entry->isFavorite(),
						'author' => html_entity_decode($entry->author(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
						'tags' => $entry->tags(),
						'feedId' => $feedId,
					];
				}
			}

			$feedsData[$feedId] = [
				'id' => $feedId,
				'name' => html_entity_decode($feed->name(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
				'favicon' => $feed->favicon(),
				'website' => $feed->website(),
				'entries' => $entries,
				'nbUnread' => $feed->nbNotRead(),
				// Include all current settings
				'currentLimit' => $limit,
				'currentFontSize' => $fontSize,
				'currentHeaderColor' => $headerColor,
				'currentMaxHeight' => $maxHeight,
				'currentDisplayMode' => $displayMode,
			];
		}

		echo json_encode($feedsData);
		exit;
	}

	public function getLayoutAction() {
		$this->noCacheHeaders();
		header('Content-Type: application/json');
		try {
			$layout = $this->getLayout();
			$userConf = FreshRSS_Context::userConf();
			$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
			$activeTabKey = $mode === 'categories'
				? FreshVibesViewExtension::ACTIVE_TAB_CATEGORY_CONFIG_KEY
				: FreshVibesViewExtension::ACTIVE_TAB_CONFIG_KEY;
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
				$activeTabId = $layout[0]['id'];
				$userConf->_attribute($activeTabKey, $activeTabId);
				$userConf->save();
			}

			$feedDAO = new FreshRSS_FeedDAO();

			foreach ($layout as &$tab) {
				$tab['name'] = html_entity_decode($tab['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
				$bgColorKey = ($mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_TAB_BG_COLOR_CONFIG_PREFIX :
					FreshVibesViewExtension::TAB_BG_COLOR_CONFIG_PREFIX) .
					$tab['id'];
				$fontColorKey = ($mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_TAB_FONT_COLOR_CONFIG_PREFIX :
					FreshVibesViewExtension::TAB_FONT_COLOR_CONFIG_PREFIX) .
					$tab['id'];

				$tab['bg_color'] = $userConf->hasParam($bgColorKey) ? $userConf->attributeString($bgColorKey) : '';
				$tab['font_color'] = $userConf->hasParam($fontColorKey) ? $userConf->attributeString($fontColorKey) : '';

				// Calculate unread count for tab
				$tabUnreadCount = 0;
				foreach ($tab['columns'] as $column) {
					foreach ($column as $feedId) {
						$feedIdInt = intval($feedId);
						if ($feedIdInt > 0) {
							$feed = $feedDAO->searchById($feedIdInt);
							if ($feed !== null) {
								$tabUnreadCount += $feed->nbNotRead();
							}
						}
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
		header('Content-Type: application/json');
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['layout']) || !isset($_POST['tab_id'])) {
			http_response_code(400);
			exit;
		}
		$layoutData = json_decode($_POST['layout'], true);
		$tabId = Minz_Request::paramString('tab_id');
		if (json_last_error() === JSON_ERROR_NONE && is_array($layoutData)) {
			// Sanitize incoming data to prevent corruption
			foreach ($layoutData as $colId => &$feedIds) {
				// If a column's data is not an array, force it to be an empty one.
				if (!is_array($feedIds)) {
					$feedIds = [];
				}
			}
			unset($feedIds);

			$layout = $this->getLayout();
			foreach ($layout as $index => $tab) {
				if ($tab['id'] === $tabId) {
					$layout[$index]['columns'] = $layoutData;
					break;
				}
			}
			$this->saveLayout($layout);
			echo json_encode(['status' => 'success']);
		} else {
			http_response_code(400);
		}
		exit;
	}

	public function updateTabAction() {
		$this->validatePostRequest();
		header('Content-Type: application/json');
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['operation'])) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_request')]);
			exit;
		}

		$operation = Minz_Request::paramString('operation');
		$layout = $this->getLayout();
		$mode = FreshRSS_Context::userConf()->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';

		try {
			switch ($operation) {
				case 'add':
					if ($mode === 'categories') {
						http_response_code(403);
						echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_operation_not_allowed')]);
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
						if (!empty($feedsToMove)) {
							$firstColKey = key($layout[0]['columns']);
							$layout[0]['columns'][$firstColKey] = array_unique(array_merge($layout[0]['columns'][$firstColKey], $feedsToMove));
						}
					}
					$this->saveLayout($layout);
					echo json_encode(['status' => 'success', 'deleted_tab_id' => $tabId, 'new_layout' => $layout]);
					break;
				case 'rename':
					if ($mode === 'categories') {
						http_response_code(403);
						echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_operation_not_allowed')]);
						exit;
					}
					$tabId = Minz_Request::paramString('tab_id');
					$newName = trim(Minz_Request::paramString('value'));
					if (empty($newName)) {
						http_response_code(400);
						echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_tab_name_empty')]);
						exit;
					}
					foreach ($layout as &$tab) {
						if ($tab['id'] === $tabId) {
							$tab['name'] = $newName;
							break;
						}
					}
					$this->saveLayout($layout);
					echo json_encode(['status' => 'success']);
					break;
				case 'set_columns':
					$tabId = Minz_Request::paramString('tab_id');
					$numCols = Minz_Request::paramInt('value') ?: FreshVibesViewExtension::DEFAULT_TAB_COLUMNS;
					if ($numCols < 1 || $numCols > 6) {
						$numCols = FreshVibesViewExtension::DEFAULT_TAB_COLUMNS;
					}
					foreach ($layout as &$tab) {
						if ($tab['id'] === $tabId) {
							$tab['num_columns'] = $numCols;
							$allFeeds = array_merge(...array_values($tab['columns']));
							$newColumns = $this->buildEmptyColumns($numCols);
							/** @var array<int,int> $allFeeds */
							if (!empty($allFeeds)) {
								foreach ($allFeeds as $i => $feedId) {
									$newColumns['col' . (($i % $numCols) + 1)][] = $feedId;
								}
							}
							$tab['columns'] = $newColumns;
							break;
						}
					}
					$this->saveLayout($layout);
					echo json_encode(['status' => 'success', 'new_layout' => $layout]);
					break;
				case 'set_icon':
					$tabId = Minz_Request::paramString('tab_id');
					$icon = Minz_Request::paramString('icon');
					$color = Minz_Request::paramString('color');
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
					if ($fontColor === '') {
						$fontColor = $bgColor !== '' ? $this->getContrastColor($bgColor) : '';
					}

					$userConf = FreshRSS_Context::userConf();
					$bgPrefix = $mode === 'categories' ?
						FreshVibesViewExtension::CATEGORY_TAB_BG_COLOR_CONFIG_PREFIX :
						FreshVibesViewExtension::TAB_BG_COLOR_CONFIG_PREFIX;
					$fontPrefix = $mode === 'categories' ?
						FreshVibesViewExtension::CATEGORY_TAB_FONT_COLOR_CONFIG_PREFIX :
						FreshVibesViewExtension::TAB_FONT_COLOR_CONFIG_PREFIX;
					$userConf->_attribute($bgPrefix . $tabId, $bgColor);
					$userConf->_attribute($fontPrefix . $tabId, $fontColor);
					$userConf->save();

					echo json_encode(['status' => 'success', 'font_color' => $fontColor]);
					break;
				case 'reorder':
					if ($mode === 'categories') {
						http_response_code(403);
						echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_operation_not_allowed')]);
						exit;
					}
					$tabIds = explode(',', Minz_Request::paramString('tab_ids'));
					if (!empty($tabIds)) {
						$newLayout = [];
						foreach ($tabIds as $tabId) {
							foreach ($layout as $tab) {
								if ($tab['id'] === $tabId) {
									$newLayout[] = $tab;
									break;
								}
							}
						}
						$this->saveLayout($newLayout);
						echo json_encode(['status' => 'success']);
					} else {
						http_response_code(400);
						echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_tab_order')]);
					}
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

	private function buildEmptyColumns(int $count): array {
		$columns = [];
		for ($i = 1; $i <= $count; $i++) {
			$columns['col' . $i] = [];
		}
		return $columns;
	}

	public function setActiveTabAction() {
		$this->validatePostRequest();
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['tab_id'])) {
			http_response_code(400);
			exit;
		}
		try {
			$mode = FreshRSS_Context::userConf()->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
			$key = $mode === 'categories' ?
				FreshVibesViewExtension::ACTIVE_TAB_CATEGORY_CONFIG_KEY :
				FreshVibesViewExtension::ACTIVE_TAB_CONFIG_KEY;
			FreshRSS_Context::userConf()->_attribute($key, $_POST['tab_id']);
			FreshRSS_Context::userConf()->save();
			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			http_response_code(500);
		}
		exit;
	}

	public function saveFeedSettingsAction() {
		$this->validatePostRequest();
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['feed_id'])) {
			http_response_code(400);
			exit;
		}
		$feedId = Minz_Request::paramInt('feed_id');
		$limit = Minz_Request::paramString('limit');
		$fontSize = Minz_Request::paramString('font_size');
		$maxHeight = Minz_Request::paramString('max_height');
		$displayMode = Minz_Request::paramString('display_mode');

		$limitForValidation = is_numeric($limit) ? (int)$limit : $limit;
		$isValidMaxHeight = (is_numeric($maxHeight) && intval($maxHeight) >= 0) || in_array($maxHeight, ['unlimited', 'fit'], true);

		if (is_numeric($maxHeight) && ($maxHeight < 0 || $maxHeight > 10000)) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.fv_invalid_height')]);
			exit;
		}

		if (
			$feedId <= 0 ||
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
			$limitPrefix = $mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_LIMIT_CONFIG_PREFIX :
				FreshVibesViewExtension::LIMIT_CONFIG_PREFIX;
			$fontPrefix = $mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_FONT_SIZE_CONFIG_PREFIX :
				FreshVibesViewExtension::FONT_SIZE_CONFIG_PREFIX;
			$headerPrefix = $mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_FEED_HEADER_COLOR_CONFIG_PREFIX :
				FreshVibesViewExtension::FEED_HEADER_COLOR_CONFIG_PREFIX;
			$maxHeightPrefix = $mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_MAX_HEIGHT_CONFIG_KEY :
				FreshVibesViewExtension::MAX_HEIGHT_CONFIG_KEY;

			$displayModePrefix = $mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_FEED_DISPLAY_MODE_CONFIG_PREFIX :
				FreshVibesViewExtension::FEED_DISPLAY_MODE_CONFIG_PREFIX;

			$userConf->_attribute($limitPrefix . $feedId, $limitForValidation);
			$userConf->_attribute($fontPrefix . $feedId, $fontSize);
			$userConf->_attribute($maxHeightPrefix . $feedId, $maxHeight);
			$userConf->_attribute($displayModePrefix . $feedId, $displayMode);

			// Only update header color if it was provided in the request
			if (isset($_POST['header_color'])) {
				$headerColor = Minz_Request::paramString('header_color');
				if ($headerColor !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $headerColor)) {
					http_response_code(400);
					echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_request')]);
					exit;
				}
				if ($headerColor === '') {
					$userConf->_attribute($headerPrefix . $feedId, null);
				} else {
					$userConf->_attribute($headerPrefix . $feedId, $headerColor);
				}
			}

			$userConf->save();
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

		$feedId = Minz_Request::paramString('feed_id');  // Changed from paramInt to paramString
		$targetTabId = Minz_Request::paramString('target_tab_id');
		$sourceTabId = Minz_Request::paramString('source_tab_id');

		if ($feedId == '' || $targetTabId == '' || $sourceTabId == '') {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_request')]);
			exit;
		}

		try {
			$layout = $this->getLayout();

			// Find and remove the feed from the source tab
			foreach ($layout as &$tab) {
				if ($tab['id'] === $sourceTabId) {
					foreach ($tab['columns'] as &$column) {
						if (!is_array($column)) {
							continue;
						}

						$filtered_column = [];
						foreach ($column as $id) {
							if ($id !== $feedId) {
								$filtered_column[] = $id;
							}
						}
						$column = $filtered_column;
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
					// Add the feed ID if it's not already there
					if (!in_array($feedId, $tab['columns'][$firstColKey], true)) {
						$tab['columns'][$firstColKey][] = $feedId;
					}
					break;
				}
			}
			unset($tab);

			$this->saveLayout($layout);
			echo json_encode(['status' => 'success', 'new_layout' => $layout]);
		} catch (Exception $e) {
			http_response_code(500);
			error_log('FreshVibesView moveFeedAction error: ' . $e->getMessage());
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_server')]);
		}
		exit;
	}

	private function generateSnippet(FreshRSS_Entry $entry, int $wordLimit = 15, int $sentenceLimit = 1): string {
		$content = $entry->content();

		// Decode HTML entities first
		$content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		// For modal excerpts, preserve some HTML
		if ($wordLimit > 50) {
			// Remove the custom sanitization - trust FreshRSS's sanitization
			// Just strip all tags for safety since we're manipulating the HTML
			$content = strip_tags($content);

			// Truncate to 500 characters if needed
			if (mb_strlen($content) > 500) {
				$content = mb_substr($content, 0, 500);
				$lastSpace = mb_strrpos($content, ' ');
				if ($lastSpace !== false) {
					$content = mb_substr($content, 0, $lastSpace);
				}
				$content .= '…';
			}

			return $content;
		}

		// For regular snippets, strip all HTML
		$plainText = trim(strip_tags(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
		if (empty($plainText)) {
			return '';
		}

		// For detailed view with sentence limit
		if ($sentenceLimit > 1) {
			// Split by sentence endings
			$sentences = preg_split('/(?<=[.!?])\s+/', $plainText, -1, PREG_SPLIT_NO_EMPTY);
			if (count($sentences) <= $sentenceLimit) {
				return $plainText;
			}
			return implode(' ', array_slice($sentences, 0, $sentenceLimit)) . '…';
		}

		// Original word-based limiting
		$words = preg_split('/[\s,]+/', $plainText, $wordLimit + 1);
		return count($words) > $wordLimit ? implode(' ', array_slice($words, 0, $wordLimit)) . '…' : implode(' ', $words);
	}

	public function markFeedReadAction() {
		$this->validatePostRequest();
		header('Content-Type: application/json');

		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['feed_id'])) {
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

		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['tab_id'])) {
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

			foreach ($layout as $tab) {
				if ($tab['id'] === $tabId) {
					foreach ($tab['columns'] as $column) {
						foreach ($column as $feedId) {
							$feedIdInt = intval($feedId);
							if ($feedIdInt > 0) {
								$affected = $entryDAO->markReadFeed($feedIdInt, $idMax);
								if ($affected !== false) {
									$totalAffected += $affected;
								}
							}
						}
					}
					break;
				}
			}

			echo json_encode(['status' => 'success', 'affected' => $totalAffected]);
		} catch (Exception $e) {
			http_response_code(500);
			echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
		}
		exit;
	}

	private function getRelativeDate(int $timestamp): string {
		$diff = time() - $timestamp;

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

		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_request')]);
			exit;
		}

		$limit = Minz_Request::paramString('limit');
		$fontSize = Minz_Request::paramString('font_size');
		$headerColor = Minz_Request::paramStringNull('header_color');
		$maxHeight = Minz_Request::paramString('max_height');
		$displayMode = Minz_Request::paramString('display_mode');

		$limitForValidation = is_numeric($limit) ? (int)$limit : $limit;
		$isValidMaxHeight = (is_numeric($maxHeight) && intval($maxHeight) >= 0) || in_array($maxHeight, ['unlimited', 'fit'], true);

		if (
			!in_array($limitForValidation, FreshVibesViewExtension::ALLOWED_LIMIT_VALUES, true) ||
			!in_array($fontSize, FreshVibesViewExtension::ALLOWED_FONT_SIZES, true) ||
			!$isValidMaxHeight ||
			!in_array($displayMode, FreshVibesViewExtension::ALLOWED_DISPLAY_MODES, true)
		) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_settings')]);
			exit;
		}

		try {
			$userConf = FreshRSS_Context::userConf();
			$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
			$feedDAO = new FreshRSS_FeedDAO();
			$feeds = $feedDAO->listFeeds();

			foreach ($feeds as $feed) {
				$feedId = $feed->id();

				$limitPrefix = $mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_LIMIT_CONFIG_PREFIX :
					FreshVibesViewExtension::LIMIT_CONFIG_PREFIX;
				$fontPrefix = $mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_FONT_SIZE_CONFIG_PREFIX :
					FreshVibesViewExtension::FONT_SIZE_CONFIG_PREFIX;
				$headerPrefix = $mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_FEED_HEADER_COLOR_CONFIG_PREFIX :
					FreshVibesViewExtension::FEED_HEADER_COLOR_CONFIG_PREFIX;
				$maxHeightPrefix = $mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_MAX_HEIGHT_CONFIG_KEY :
					FreshVibesViewExtension::MAX_HEIGHT_CONFIG_KEY;
				$displayModePrefix = $mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_FEED_DISPLAY_MODE_CONFIG_PREFIX :
					FreshVibesViewExtension::FEED_DISPLAY_MODE_CONFIG_PREFIX;

				$userConf->_attribute($limitPrefix . $feedId, $limitForValidation);
				$userConf->_attribute($fontPrefix . $feedId, $fontSize);
				$userConf->_attribute($maxHeightPrefix . $feedId, $maxHeight);
				$userConf->_attribute($displayModePrefix . $feedId, $displayMode);

				if ($headerColor !== null) {
					if ($headerColor === '') {
						$userConf->_attribute($headerPrefix . $feedId, null);
					} else {
						$userConf->_attribute($headerPrefix . $feedId, $headerColor);
					}
				}
			}

			$userConf->save();
			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			http_response_code(500);
			echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
		}
		exit;
	}

	public function bulkApplyTabSettingsAction() {
		$this->validatePostRequest();
		header('Content-Type: application/json');

		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_request')]);
			exit;
		}

		$numColumns = Minz_Request::paramInt('num_columns') ?: FreshVibesViewExtension::DEFAULT_TAB_COLUMNS;
		$bgColor = Minz_Request::paramString('bg_color');
		$fontColor = Minz_Request::paramString('font_color');

		if ($numColumns < 1 || $numColumns > 6) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => 'Invalid number of columns']);
			exit;
		}

		try {
			$layout = $this->getLayout();
			$userConf = FreshRSS_Context::userConf();
			$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';

			foreach ($layout as &$tab) {
				$tab['num_columns'] = $numColumns;
				$allFeeds = array_merge(...array_values($tab['columns']));
				$newColumns = $this->buildEmptyColumns($numColumns);
				/** @var array<int,int> $allFeeds */
				if (!empty($allFeeds)) {
					foreach ($allFeeds as $i => $feedId) {
						$newColumns['col' . (($i % $numColumns) + 1)][] = $feedId;
					}
				}
				$tab['columns'] = $newColumns;

				$bgPrefix = $mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_TAB_BG_COLOR_CONFIG_PREFIX :
					FreshVibesViewExtension::TAB_BG_COLOR_CONFIG_PREFIX;
				$fontPrefix = $mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_TAB_FONT_COLOR_CONFIG_PREFIX :
					FreshVibesViewExtension::TAB_FONT_COLOR_CONFIG_PREFIX;

				if ($bgColor != '') {
					$userConf->_attribute($bgPrefix . $tab['id'], $bgColor);
					$actualFontColor = $fontColor ?: $this->getContrastColor($bgColor);
					$userConf->_attribute($fontPrefix . $tab['id'], $actualFontColor);
				}
			}

			$this->saveLayout($layout);
			$userConf->save();

			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			http_response_code(500);
			echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
		}
		exit;
	}

	public function resetAllFeedSettingsAction() {
		$this->validatePostRequest();
		header('Content-Type: application/json');

		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			http_response_code(400);
			exit;
		}

		try {
			$userConf = FreshRSS_Context::userConf();
			$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
			$feedDAO = new FreshRSS_FeedDAO();
			$feeds = $feedDAO->listFeeds();

			foreach ($feeds as $feed) {
				$feedId = $feed->id();

				// Define all prefixes based on mode
				$limitPrefix = $mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_LIMIT_CONFIG_PREFIX :
					FreshVibesViewExtension::LIMIT_CONFIG_PREFIX;
				$fontPrefix = $mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_FONT_SIZE_CONFIG_PREFIX :
					FreshVibesViewExtension::FONT_SIZE_CONFIG_PREFIX;
				$headerPrefix = $mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_FEED_HEADER_COLOR_CONFIG_PREFIX :
					FreshVibesViewExtension::FEED_HEADER_COLOR_CONFIG_PREFIX;
				$maxHeightPrefix = $mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_MAX_HEIGHT_CONFIG_KEY :
					FreshVibesViewExtension::MAX_HEIGHT_CONFIG_KEY;
				$displayModePrefix = $mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_FEED_DISPLAY_MODE_CONFIG_PREFIX :
					FreshVibesViewExtension::FEED_DISPLAY_MODE_CONFIG_PREFIX;

				// Set all attributes to null to remove them
				$keys = [
					$limitPrefix . $feedId,
					$fontPrefix . $feedId,
					$headerPrefix . $feedId,
					$maxHeightPrefix . $feedId,
					$displayModePrefix . $feedId,
				];
				foreach ($keys as $key) {
					$userConf->_attribute($key, null);
				}
			}

			$userConf->save();
			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			http_response_code(500);
			echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
		}
		exit;
	}

	public function resetAllTabSettingsAction() {
		$this->validatePostRequest();
		header('Content-Type: application/json');

		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			http_response_code(400);
			exit;
		}

		try {
			$layout = $this->getLayout();
			$userConf = FreshRSS_Context::userConf();
			$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';

			foreach ($layout as &$tab) {
				$tab['num_columns'] = FreshVibesViewExtension::DEFAULT_TAB_COLUMNS;
				$allFeeds = array_merge(...array_values($tab['columns']));
				$tab['icon'] = '';
				$tab['icon_color'] = '';
				$newColumns = $this->buildEmptyColumns(FreshVibesViewExtension::DEFAULT_TAB_COLUMNS);
				/** @var array<int,int> $allFeeds */
				if (!empty($allFeeds)) {
					foreach ($allFeeds as $i => $feedId) {
						$newColumns['col' . (($i % FreshVibesViewExtension::DEFAULT_TAB_COLUMNS) + 1)][] = $feedId;
					}
				}
				$tab['columns'] = $newColumns;

				$bgPrefix = $mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_TAB_BG_COLOR_CONFIG_PREFIX :
					FreshVibesViewExtension::TAB_BG_COLOR_CONFIG_PREFIX;
				$fontPrefix = $mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_TAB_FONT_COLOR_CONFIG_PREFIX :
					FreshVibesViewExtension::TAB_FONT_COLOR_CONFIG_PREFIX;

				$userConf->_attribute($bgPrefix . $tab['id'], null);
				$userConf->_attribute($fontPrefix . $tab['id'], null);
			}

			$this->saveLayout($layout);
			$userConf->save();

			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			http_response_code(500);
			echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
		}
		exit;
	}

	private function noCacheHeaders() {
		header('Cache-Control: no-cache, no-store, must-revalidate');
		header('Pragma: no-cache');
		header('Expires: 0');
	}

	private function validatePostRequest(): void {
		$this->noCacheHeaders();
		header('Content-Type: application/json');

		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			http_response_code(405);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_request')]);
			exit;
		}

		if (!FreshRSS_Auth::isCsrfOk()) {
			http_response_code(403);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.csrf_error')]);
			exit;
		}
	}
}
