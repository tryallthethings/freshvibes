<?php

declare(strict_types=1);

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
		$currentState = FreshRSS_Context::$state;
		$feedsData = [];
		$dateFormat = $userConf->attributeString(FreshVibesViewExtension::DATE_FORMAT_CONFIG_KEY)
			?: FreshVibesViewExtension::DEFAULT_DATE_FORMAT;
		$entriesCap = $this->entriesCap($userConf);

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
			$queryLimit = $this->resolveQueryLimit($limit, $entriesCap);

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
			FreshVibesViewExtension::DASHBOARD_LAYOUT_CONFIG_KEY => 'tabs',
			FreshVibesViewExtension::ANIMATIONS_ENABLED_CONFIG_KEY => true,
			FreshVibesViewExtension::EMPTY_FEEDS_DISPLAY_CONFIG_KEY => 'show',
		];

		foreach ($defaults as $key => $value) {
			$storedValue = $userConf->param($key);


			if (!$userConf->hasParam($key) || $storedValue === null) {
				// Condition 1: Key is missing or its value is null. Set the default.
				$userConf->_attribute($key, $value);
				$configChanged = true;
			} elseif (is_bool($value) && !is_bool($storedValue)) {
				// Condition 2: It's a boolean setting, but the stored type is wrong (e.g. int).
				// Coerce the existing value to a boolean to migrate it.
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
				($mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_LIMIT_CONFIG_PREFIX :
					FreshVibesViewExtension::LIMIT_CONFIG_PREFIX) . $feedId => FreshVibesViewExtension::DEFAULT_ARTICLES_PER_FEED,
				($mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_FONT_SIZE_CONFIG_PREFIX :
					FreshVibesViewExtension::FONT_SIZE_CONFIG_PREFIX) . $feedId => FreshVibesViewExtension::DEFAULT_FONT_SIZE,
				($mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_MAX_HEIGHT_CONFIG_KEY :
					FreshVibesViewExtension::MAX_HEIGHT_CONFIG_KEY) . $feedId => FreshVibesViewExtension::DEFAULT_MAX_HEIGHT_CONFIG_KEY,
				($mode === 'categories' ?
					FreshVibesViewExtension::CATEGORY_FEED_DISPLAY_MODE_CONFIG_PREFIX :
					FreshVibesViewExtension::FEED_DISPLAY_MODE_CONFIG_PREFIX) . $feedId => FreshVibesViewExtension::DEFAULT_DISPLAY_MODE,
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
				$this->saveLayout($layout);
			}
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

	private function saveLayout(array $layout): void {
		// Clean up any duplicates before saving
		$this->deduplicateLayout($layout);
		$userConf = FreshRSS_Context::userConf();
		$mode = $userConf->attributeString(FreshVibesViewExtension::MODE_CONFIG_KEY) ?? 'custom';
		$layoutKey = $mode === 'categories'
			? FreshVibesViewExtension::CATEGORY_LAYOUT_CONFIG_KEY
			: FreshVibesViewExtension::LAYOUT_CONFIG_KEY;
		$userConf->_attribute($layoutKey, $layout);
		$userConf->save();
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

			// Build the id -> unread map once; the previous code issued one searchById() per placed
			// feed on every layout read.
			$unreadByFeedId = [];
			foreach (FreshRSS_Factory::createFeedDao()->listFeeds() as $feed) {
				$unreadByFeedId[$feed->id()] = $feed->nbNotRead();
			}

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

		$rawLayout = Minz_Request::paramString('layout');
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
		$columns = LayoutSchema::validateColumns($decoded, $this->subscribedFeedIds(), $numColumns);
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
					echo json_encode(['status' => 'success', 'deleted_tab_id' => $tabId, 'new_layout' => $layout]);
					break;
				case 'rename':
					if ($mode === 'categories') {
						http_response_code(403);
						echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_operation_not_allowed')]);
						exit;
					}
					$tabId = Minz_Request::paramString('tab_id');
					$newName = LayoutSchema::normalizeName(Minz_Request::paramString('value'));
					if ($newName === null) {
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
							$tab['columns'] = LayoutSchema::redistributeColumns($tab, $numCols);
							$tab['num_columns'] = $numCols;
							break;
						}
					}
					unset($tab);
					$this->saveLayout($layout);
					echo json_encode(['status' => 'success', 'new_layout' => $layout]);
					break;
				case 'set_icon':
					$tabId = Minz_Request::paramString('tab_id');
					$icon = Minz_Request::paramString('icon');
					$color = Minz_Request::paramString('color');
					if (mb_strlen($icon) > LayoutSchema::MAX_ICON_LENGTH
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
			$key = $mode === 'categories' ?
				FreshVibesViewExtension::ACTIVE_TAB_CATEGORY_CONFIG_KEY :
				FreshVibesViewExtension::ACTIVE_TAB_CONFIG_KEY;
			$userConf->_attribute($key, $tabId);
			$userConf->save();
			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			$this->failWithGenericError('setActiveTabAction', $e);
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
		$isValidMaxHeight = in_array($maxHeight, ['unlimited', 'fit'], true)
			|| (is_numeric($maxHeight) && (int)$maxHeight >= 0 && (int)$maxHeight <= LayoutSchema::MAX_FEED_HEIGHT);

		if (is_numeric($maxHeight) && !$isValidMaxHeight) {
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
				if ($headerColor !== '' && !Sanitizer::isHexColor($headerColor)) {
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
		echo json_encode(['status' => 'success', 'new_layout' => $layout]);
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
			$feedDAO = FreshRSS_Factory::createFeedDao();
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
			$this->failWithGenericError('bulkApplyFeedSettingsAction', $e);
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

			foreach ($layout as &$tab) {
				$tab['columns'] = LayoutSchema::redistributeColumns($tab, $numColumns);
				$tab['num_columns'] = $numColumns;

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
			$this->failWithGenericError('bulkApplyTabSettingsAction', $e);
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
			$feedDAO = FreshRSS_Factory::createFeedDao();
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
			$this->failWithGenericError('resetAllFeedSettingsAction', $e);
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
				$tab['columns'] = LayoutSchema::redistributeColumns($tab, FreshVibesViewExtension::DEFAULT_TAB_COLUMNS);
				$tab['num_columns'] = FreshVibesViewExtension::DEFAULT_TAB_COLUMNS;
				$tab['icon'] = '';
				$tab['icon_color'] = '';

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

	/** Respond with 400 and a generic message. */
	private function failWithBadRequest(?string $message = null): void {
		http_response_code(400);
		echo json_encode([
			'status' => 'error',
			'message' => $message ?? _t('ext.FreshVibesView.error_invalid_request'),
		]);
		exit;
	}

	/** Feed IDs the current user is subscribed to. @return list<int> */
	private function subscribedFeedIds(): array {
		$ids = [];
		foreach (FreshRSS_Factory::createFeedDao()->listFeeds() as $feed) {
			$ids[] = $feed->id();
		}
		return $ids;
	}

	private function deduplicateLayout(array &$layout): void {
		LayoutSchema::deduplicate($layout);
	}

	public function saveCategoryOrderAction() {
		$this->validatePostRequest();
		header('Content-Type: application/json');

		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['category_ids'])) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_request')]);
			exit;
		}

		$categoryIds = explode(',', Minz_Request::paramString('category_ids'));
		if (empty($categoryIds)) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_invalid_request')]);
			exit;
		}

		try {
			$categoryDAO = FreshRSS_Factory::createCategoryDao();
			$position = 1;

			foreach ($categoryIds as $catIdStr) {
				// Extract numeric ID from 'cat-123' format
				if (preg_match('/^cat-(\d+)$/', $catIdStr, $matches)) {
					$catId = (int)$matches[1];
					$category = $categoryDAO->searchById($catId);
					if ($category !== null) {
						$category->_attribute('position', $position);
						$categoryDAO->updateCategory($catId, [
							'name' => $category->name(),
							'kind' => $category->kind(),
							'attributes' => $category->attributes(),
						]);
						$position++;
					}
				}
			}

			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			http_response_code(500);
			error_log('FreshVibesView saveCategoryOrderAction error: ' . $e->getMessage());
			echo json_encode(['status' => 'error', 'message' => _t('ext.FreshVibesView.error_server')]);
		}
		exit;
	}
}
