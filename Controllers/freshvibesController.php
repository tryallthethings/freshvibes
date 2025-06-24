<?php

declare(strict_types=1);

class FreshExtension_freshvibes_Controller extends Minz_ActionController {

	private function noCacheHeaders() {
		header('Cache-Control: no-cache, no-store, must-revalidate');
		header('Pragma: no-cache');
		header('Expires: 0');
	}

	private function getMode(): string {
		$userConf = FreshRSS_Context::userConf();
		$mode = $userConf->param(FreshVibesViewExtension::MODE_CONFIG_KEY, 'custom');
		return $mode === 'categories' ? 'categories' : 'custom';
	}

	private function getLayout(): array {
		$userConf = FreshRSS_Context::userConf();
		$mode = $this->getMode();
		$layoutKey = $mode === 'categories'
			? FreshVibesViewExtension::CATEGORY_LAYOUT_CONFIG_KEY
			: FreshVibesViewExtension::LAYOUT_CONFIG_KEY;
		$layout = $userConf->hasParam($layoutKey)
			? $userConf->param($layoutKey)
			: null;

		// Get the new feed position setting
		$newFeedPosition = $userConf->param(FreshVibesViewExtension::NEW_FEED_POSITION_CONFIG_KEY, 'bottom');

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

		// Rest of the method for custom mode...
		if ($layout === null) {
			$numCols = FreshVibesViewExtension::DEFAULT_TAB_COLUMNS;
			$feedDAO = new FreshRSS_FeedDAO();

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

		return is_array($layout) ? $layout : [];
	}

	private function saveLayout(array $layout): void {
		$userConf = FreshRSS_Context::userConf();
		$mode = $this->getMode();
		$layoutKey = $mode === 'categories'
			? FreshVibesViewExtension::CATEGORY_LAYOUT_CONFIG_KEY
			: FreshVibesViewExtension::LAYOUT_CONFIG_KEY;
		$userConf->_attribute($layoutKey, $layout);
		$userConf->save();
	}

	public function indexAction() {
		$this->noCacheHeaders();

		$factory = new FreshRSS_Factory();
		$feedDAO = $factory->createFeedDAO();
		$entryDAO = $factory->createEntryDAO();

		try {
			FreshRSS_Context::updateUsingRequest(true);
		} catch (FreshRSS_Context_Exception $e) {
			Minz_Error::error(404);
			return;
		}

		$feeds = $feedDAO->listFeeds();
		$userConf = FreshRSS_Context::userConf();
		$mode = $this->getMode();
		$currentState = FreshRSS_Context::$state;
		$feedsData = [];
		$dateFormat = $userConf->param(FreshVibesViewExtension::DATE_FORMAT_CONFIG_KEY, 'Y-m-d H:i');
		$dateMode = $userConf->param(FreshVibesViewExtension::DATE_MODE_CONFIG_KEY, 'absolute');

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
			if ($userConf->hasParam($limitKey)) {
				$limit = $userConf->param($limitKey);
			} else {
				$limit = FreshVibesViewExtension::DEFAULT_ARTICLES_PER_FEED;
			}

			$limitForValidation = is_numeric($limit) ? (int)$limit : $limit;
			if (!in_array($limitForValidation, FreshVibesViewExtension::ALLOWED_LIMIT_VALUES, true)) {
				$limit = FreshVibesViewExtension::DEFAULT_ARTICLES_PER_FEED;
			}
			$queryLimit = ($limit === 'unlimited') ? null : (int)$limit;

			if ($userConf->hasParam($fontSizeKey)) {
				$fontSize = $userConf->attributeString($fontSizeKey);
			} else {
				$fontSize = FreshVibesViewExtension::DEFAULT_FONT_SIZE;
			}
			if (!in_array($fontSize, FreshVibesViewExtension::ALLOWED_FONT_SIZES)) {
				$fontSize = FreshVibesViewExtension::DEFAULT_FONT_SIZE;
			}

			$maxHeightKey = ($mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_MAX_HEIGHT_CONFIG_KEY :
				FreshVibesViewExtension::MAX_HEIGHT_CONFIG_KEY) .
				$feedId;
			if ($userConf->hasParam($maxHeightKey)) {
				$maxHeight = $userConf->attributeString($maxHeightKey);
			} else {
				$maxHeight = FreshVibesViewExtension::DEFAULT_MAX_HEIGHT_CONFIG_KEY;
			}
			if (!in_array($maxHeight, FreshVibesViewExtension::ALLOWED_MAX_HEIGHTS_CONFIG_KEY, true) && !is_numeric($maxHeight)) {
				$maxHeight = FreshVibesViewExtension::DEFAULT_MAX_HEIGHT_CONFIG_KEY;
			}

			$headerColorKey = ($mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_FEED_HEADER_COLOR_CONFIG_PREFIX :
				FreshVibesViewExtension::FEED_HEADER_COLOR_CONFIG_PREFIX) .
				$feedId;
			if ($userConf->hasParam($headerColorKey)) {
				$headerColor = $userConf->attributeString($headerColorKey);
			} else {
				$headerColor = '';
			}

			$displayModeKey = ($mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_FEED_DISPLAY_MODE_CONFIG_PREFIX :
				FreshVibesViewExtension::FEED_DISPLAY_MODE_CONFIG_PREFIX) .
				$feedId;
			if ($userConf->hasParam($displayModeKey)) {
				$displayMode = $userConf->attributeString($displayModeKey);
			} else {
				$displayMode = FreshVibesViewExtension::DEFAULT_DISPLAY_MODE;
			}
			if (!in_array($displayMode, FreshVibesViewExtension::ALLOWED_DISPLAY_MODES)) {
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
							'isRead' => $entry->isRead(),
							'isFavorite' => $entry->isFavorite(),
							'author' => html_entity_decode($entry->author(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
							'tags' => $entry->tags(),
							'feedId' => $feedId,
						];
					}
				}
			} catch (Throwable $e) {
				error_log('FreshVibesView error in indexAction for feed ' . $feedId . ': ' . $e->getMessage());
				$entries = ['error' => 'Error loading entries for feed ' . $feedId . '. Please check system logs.'];
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
		@$this->view->currentSort = FreshRSS_Context::$sort;
		@$this->view->currentOrder = FreshRSS_Context::$order;
		@$this->view->feedsData = $feedsData;
		@$this->view->getLayoutUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'getlayout'], 'json', false);
		@$this->view->saveLayoutUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'savelayout'], 'json', false);
		@$this->view->saveFeedSettingsUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'savefeedsettings'], 'json', false);
		@$this->view->tabActionUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'updatetab'], 'json', false);
		@$this->view->moveFeedUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'movefeed'], 'json', false);
		@$this->view->setActiveTabUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'setactivetab'], 'json', false);
		@$this->view->markFeedReadUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'markfeedread'], 'json', false);
		@$this->view->markTabReadUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'marktabread'], 'json', false);
		@$this->view->markReadUrl = Minz_Url::display(['c' => 'entry', 'a' => 'read'], 'json', false);
		@$this->view->bookmarkUrl = Minz_Url::display(['c' => 'entry', 'a' => 'bookmark'], 'json', false);
		@$this->view->searchAuthorUrl = Minz_Url::display(['a' => 'normal'], 'html', false);
		@$this->view->searchTagUrl = Minz_Url::display(['a' => 'normal'], 'html', false);

		@$this->view->viewMode = $mode;
		@$this->view->rss_title = _t('ext.FreshVibesView.title');
		@$this->view->refreshEnabled = (bool)$userConf->param(FreshVibesViewExtension::REFRESH_ENABLED_CONFIG_KEY, 0);
		@$this->view->refreshInterval = (int)$userConf->param(FreshVibesViewExtension::REFRESH_INTERVAL_CONFIG_KEY, 15);
		@$this->view->feedUrl = Minz_Url::display([], 'html', false) . '?get=f_';
		@$this->view->categories = FreshRSS_Context::categories();
		@$this->view->confirmTabDelete = (bool)$userConf->param(FreshVibesViewExtension::CONFIRM_TAB_DELETE_CONFIG_KEY, 1);
		@$this->view->entryClickMode = $userConf->param(FreshVibesViewExtension::ENTRY_CLICK_MODE_CONFIG_KEY, 'modal');
		@$this->view->dateMode = $userConf->param(FreshVibesViewExtension::DATE_MODE_CONFIG_KEY, 'absolute');
		@$this->view->confirmMarkRead = (bool)$userConf->param(FreshVibesViewExtension::CONFIRM_MARK_READ_CONFIG_KEY, 1);
		@$this->view->refreshFeedsUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'refreshfeeds'], 'json', false);
		@$this->view->feedSettingsUrl = Minz_Url::display() . '?c=subscription&a=feed&id=';
		@$this->view->categorySettingsUrl = Minz_Url::display() . '?c=category&a=update&id=';

		@$tags = FreshRSS_Context::labels(true);
		@$this->view->tags = $tags;
		$nbUnreadTags = 0;
		foreach ($tags as $tag) {
			$nbUnreadTags += $tag->nbUnread();
		}
		@$this->view->nbUnreadTags = $nbUnreadTags;

		$this->view->_path(FreshVibesViewExtension::CONTROLLER_NAME_BASE . '/index.phtml');
	}

	public function refreshfeedsAction() {
		$this->noCacheHeaders();
		header('Content-Type: application/json');

		$factory = new FreshRSS_Factory();
		$feedDAO = $factory->createFeedDAO();
		$entryDAO = $factory->createEntryDAO();
		$userConf = FreshRSS_Context::userConf();
		$mode = $this->getMode();
		$currentState = FreshRSS_Context::$state;
		$feedsData = [];
		$dateFormat = $userConf->param(FreshVibesViewExtension::DATE_FORMAT_CONFIG_KEY, 'Y-m-d H:i');
		$dateMode = $userConf->param(FreshVibesViewExtension::DATE_MODE_CONFIG_KEY, 'absolute');
		$feeds = $feedDAO->listFeeds();

		foreach ($feeds as $feed) {
			$feedId = $feed->id();
			$limitKey = ($mode === 'categories' ?
				FreshVibesViewExtension::CATEGORY_LIMIT_CONFIG_PREFIX :
				FreshVibesViewExtension::LIMIT_CONFIG_PREFIX) .
				$feedId;
			$limit = $userConf->hasParam($limitKey) ? $userConf->param($limitKey) : FreshVibesViewExtension::DEFAULT_ARTICLES_PER_FEED;

			$queryLimit = ($limit === 'unlimited') ? null : (int)$limit;
			$fontSize = $userConf->attributeString(FreshVibesViewExtension::FONT_SIZE_CONFIG_PREFIX . $feedId, FreshVibesViewExtension::DEFAULT_FONT_SIZE);
			$headerColor = $userConf->attributeString(FreshVibesViewExtension::FEED_HEADER_COLOR_CONFIG_PREFIX . $feedId, '');
			$maxHeight = $userConf->attributeString(FreshVibesViewExtension::MAX_HEIGHT_CONFIG_KEY . $feedId, FreshVibesViewExtension::DEFAULT_MAX_HEIGHT_CONFIG_KEY);
			$displayMode = $userConf->attributeString(FreshVibesViewExtension::FEED_DISPLAY_MODE_CONFIG_PREFIX . $feedId, FreshVibesViewExtension::DEFAULT_DISPLAY_MODE);

			$entryGenerator = $entryDAO->listWhere('f', $feedId, $currentState, null, '0', '0', (FreshRSS_Context::$sort ?? 'date'), (FreshRSS_Context::$order ?? 'DESC'), '0', 0, $queryLimit ?? 0, 0);
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
				// Include current settings so the UI doesn't have to guess
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
			$mode = $this->getMode();
			$activeTabKey = $mode === 'categories'
				? FreshVibesViewExtension::ACTIVE_TAB_CATEGORY_CONFIG_KEY
				: FreshVibesViewExtension::ACTIVE_TAB_CONFIG_KEY;
			$activeTabId = $userConf->hasParam($activeTabKey)
				? $userConf->param($activeTabKey)
				: null;
			$activeTabExists = false;
			if ($activeTabId) {
				foreach ($layout as $tab) {
					if ($tab['id'] === $activeTabId) {
						$activeTabExists = true;
						break;
					}
				}
			}
			if (!$activeTabExists && !empty($layout)) {
				$activeTabId = $layout[0]['id'];
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

				$tab['bg_color'] = $userConf->hasParam($bgColorKey)
					? $userConf->param($bgColorKey)
					: '';
				$tab['font_color'] = $userConf->hasParam($fontColorKey)
					? $userConf->param($fontColorKey)
					: '';

				// Calculate unread count for tab
				$tabUnreadCount = 0;
				foreach ($tab['columns'] as $column) {
					foreach ($column as $feedId) {
						$feedIdInt = intval($feedId);
						if ($feedIdInt > 0) {
							$feed = $feedDAO->searchById($feedIdInt);
							if ($feed) {
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
			echo json_encode(['error' => 'Server error loading layout.']);
		}
		exit;
	}

	public function saveLayoutAction() {
		$this->noCacheHeaders();
		header('Content-Type: application/json');
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['layout']) || !isset($_POST['tab_id'])) {
			http_response_code(400);
			exit;
		}
		$layoutData = json_decode($_POST['layout'], true);
		$tabId = Minz_Request::paramString('tab_id');
		if (json_last_error() === JSON_ERROR_NONE && is_array($layoutData)) {
			try {
				// FIX: Sanitize incoming data to prevent corruption ---
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
			} catch (Exception $e) {
				http_response_code(500);
			}
		} else {
			http_response_code(400);
		}
		exit;
	}

	public function updateTabAction() {
		$this->noCacheHeaders();
		header('Content-Type: application/json');
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['operation'])) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
			exit;
		}

		$operation = Minz_Request::paramString('operation');
		$layout = $this->getLayout();
		$mode = $this->getMode();

		try {
			switch ($operation) {
				case 'add':
					if ($mode === 'categories') {
						http_response_code(403);
						echo json_encode(['status' => 'error', 'message' => 'Operation not allowed.']);
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
						echo json_encode(['status' => 'error', 'message' => 'Operation not allowed.']);
						exit;
					}
					if (count($layout) <= 1) {
						http_response_code(400);
						echo json_encode(['status' => 'error', 'message' => 'Cannot delete the last tab.']);
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
						echo json_encode(['status' => 'error', 'message' => 'Operation not allowed.']);
						exit;
					}
					$tabId = Minz_Request::paramString('tab_id');
					$newName = trim(Minz_Request::paramString('value'));
					if (empty($newName)) {
						http_response_code(400);
						echo json_encode(['status' => 'error', 'message' => 'Tab name cannot be empty.']);
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
					$numCols = Minz_Request::paramInt('value', FreshVibesViewExtension::DEFAULT_TAB_COLUMNS);
					if ($numCols < 1 || $numCols > 6) {
						$numCols = FreshVibesViewExtension::DEFAULT_TAB_COLUMNS;
					}
					foreach ($layout as &$tab) {
						if ($tab['id'] === $tabId) {
							$tab['num_columns'] = $numCols;
							$allFeeds = array_merge(...array_values($tab['columns']));
							$newColumns = $this->buildEmptyColumns($numCols);
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
					if ($bgColor === null) {
						if (method_exists($userConf, 'removeAttribute')) {
							$userConf->removeAttribute($bgPrefix . $tabId);
						} else {
							unset($userConf->{$bgPrefix . $tabId});
						}
					} else {
						$userConf->_attribute($bgPrefix . $tabId, $bgColor);
					}

					if ($fontColor === null) {
						if (method_exists($userConf, 'removeAttribute')) {
							$userConf->removeAttribute($fontPrefix . $tabId);
						} else {
							unset($userConf->{$fontPrefix . $tabId});
						}
					} else {
						$userConf->_attribute($fontPrefix . $tabId, $fontColor);
					}
					$userConf->save();

					echo json_encode(['status' => 'success', 'font_color' => $fontColor]);
					break;
				case 'reorder':
					if ($mode === 'categories') {
						http_response_code(403);
						echo json_encode(['status' => 'error', 'message' => 'Operation not allowed.']);
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
						echo json_encode(['status' => 'error', 'message' => 'Invalid tab order.']);
					}
					break;
				default:
					http_response_code(400);
					echo json_encode(['status' => 'error', 'message' => 'Unknown tab operation.']);
					exit;
			}
		} catch (Exception $e) {
			http_response_code(500);
			echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
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
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['tab_id'])) {
			http_response_code(400);
			exit;
		}
		try {
			$mode = $this->getMode();
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
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['feed_id'])) {
			http_response_code(400);
			exit;
		}
		$feedId = Minz_Request::paramInt('feed_id');
		$limit = Minz_Request::paramString('limit');
		$fontSize = Minz_Request::paramString('font_size');
		$headerColor = Minz_Request::paramStringNull('header_color');
		$maxHeight = Minz_Request::paramString('max_height');
		$displayMode = Minz_Request::paramString('display_mode');

		$limitForValidation = is_numeric($limit) ? (int)$limit : $limit;
		$isValidMaxHeight = (is_numeric($maxHeight) && intval($maxHeight) >= 0) || in_array($maxHeight, ['unlimited', 'fit'], true);

		if (
			$feedId <= 0 ||
			!in_array($limitForValidation, FreshVibesViewExtension::ALLOWED_LIMIT_VALUES, true) ||
			!in_array($fontSize, FreshVibesViewExtension::ALLOWED_FONT_SIZES) ||
			!$isValidMaxHeight ||
			!in_array($displayMode, FreshVibesViewExtension::ALLOWED_DISPLAY_MODES)
		) {
			http_response_code(400);
			exit;
		}
		try {
			$userConf = FreshRSS_Context::userConf();
			$mode = $this->getMode();
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
				$userConf->_attribute($headerPrefix . $feedId, $headerColor);
			}
			$userConf->save();
			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			http_response_code(500);
			error_log('FreshVibesView saveFeedSettingsAction error: ' . $e->getMessage());
			echo json_encode(['status' => 'error', 'message' => 'Server error']);
		}
		exit;
	}

	public function moveFeedAction() {
		$this->noCacheHeaders();
		header('Content-Type: application/json');

		// FIX: Treat all IDs as strings to prevent type mismatch issues.
		$feedId = Minz_Request::paramString('feed_id');
		$targetTabId = Minz_Request::paramString('target_tab_id');
		$sourceTabId = Minz_Request::paramString('source_tab_id');

		if (!$feedId || !$targetTabId || !$sourceTabId) {
			http_response_code(400);
			exit;
		}

		try {
			$layout = $this->getLayout();

			// Find and remove the feed from the source tab
			foreach ($layout as &$tab) {
				if ($tab['id'] === $sourceTabId) {
					foreach ($tab['columns'] as &$column) {
						// Ensure we are working with an array
						if (!is_array($column)) {
							continue;
						}
						// Use a temporary variable to hold the filtered array
						$filtered_column = [];
						foreach ($column as $id) {
							if ((string)$id !== $feedId) {
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
			echo json_encode(['status' => 'error', 'message' => 'An internal error occurred.']);
		}
		exit;
	}

	private function generateSnippet(FreshRSS_Entry $entry, int $wordLimit = 15, int $sentenceLimit = 1): string {
		$content = $entry->content() ?? '';

		// Decode HTML entities first
		$content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		// For modal excerpts, preserve some HTML
		if ($wordLimit > 50) {
			// Create a whitelist of allowed tags and attributes
			$allowedTags = ['a', 'b', 'i', 'em', 'strong', 'p', 'br', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre'];
			$allowedAttributes = ['href', 'target', 'rel'];

			// Use strip_tags with allowed tags to remove dangerous elements
			$allowedTagsString = '<' . implode('><', $allowedTags) . '>';
			$content = strip_tags($content, $allowedTagsString);

			// Additional safety: ensure links have safe attributes
			$dom = new DOMDocument();
			$dom->encoding = 'UTF-8';
			libxml_use_internal_errors(true);
			if ($dom->loadHTML('<?xml encoding="UTF-8"><div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
				$xpath = new DOMXPath($dom);

				// Sanitize all links
				$links = $xpath->query('//a');
				foreach ($links as $link) {
					if ($link instanceof DOMElement) {
						// Remove all attributes except allowed ones
						$attrs = [];
						foreach ($link->attributes as $attr) {
							if (!in_array($attr->nodeName, $allowedAttributes)) {
								$attrs[] = $attr->nodeName;
							}
						}
						foreach ($attrs as $attr) {
							$link->removeAttribute($attr);
						}

						// Ensure target="_blank" links have rel="noopener noreferrer"
						if ($link->getAttribute('target') === '_blank') {
							$link->setAttribute('rel', 'noopener noreferrer');
						}
					}
				}

				// Get the cleaned content
				$content = '';
				foreach ($dom->documentElement->childNodes as $child) {
					$content .= $dom->saveHTML($child);
				}
			}
			libxml_clear_errors();

			// Truncate if needed
			if (mb_strlen($content) > 500) {
				// Use DOM parsing to safely truncate HTML
				$dom = new DOMDocument();
				$dom->encoding = 'UTF-8';

				libxml_use_internal_errors(true);
				$dom->loadHTML('<?xml encoding="UTF-8"><div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
				libxml_clear_errors();

				$textContent = $dom->textContent;

				if (mb_strlen($textContent) > 500) {
					// Find truncation point in text content
					$truncatedText = mb_substr($textContent, 0, 500);
					$lastSpace = mb_strrpos($truncatedText, ' ');
					if ($lastSpace !== false) {
						$truncatedText = mb_substr($truncatedText, 0, $lastSpace);
					}

					// Walk through DOM and truncate at the right point
					$currentLength = 0;
					$targetLength = mb_strlen($truncatedText);
					$this->truncateNode($dom->documentElement, $currentLength, $targetLength);

					// Get the cleaned HTML
					$content = '';
					foreach ($dom->documentElement->childNodes as $child) {
						$content .= $dom->saveHTML($child);
					}
					$content .= '…';
				}
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

	private function truncateNode(DOMNode $node, int &$currentLength, int $targetLength): bool {
		$nodesToRemove = [];

		foreach ($node->childNodes as $child) {
			if ($currentLength >= $targetLength) {
				$nodesToRemove[] = $child;
				continue;
			}

			if ($child->nodeType === XML_TEXT_NODE) {
				$textLength = mb_strlen($child->textContent);
				if ($currentLength + $textLength > $targetLength) {
					// Truncate this text node
					$remaining = $targetLength - $currentLength;
					$truncatedText = mb_substr($child->textContent, 0, $remaining);
					$child->textContent = $truncatedText;
					$currentLength = $targetLength;
				} else {
					$currentLength += $textLength;
				}
			} else {
				// Recursively process child elements
				if (!$this->truncateNode($child, $currentLength, $targetLength)) {
					$nodesToRemove[] = $child;
				}
			}
		}

		// Remove nodes that exceed the limit
		foreach ($nodesToRemove as $nodeToRemove) {
			$node->removeChild($nodeToRemove);
		}

		return $node->hasChildNodes() || $node->nodeType === XML_TEXT_NODE;
	}

	public function markFeedReadAction() {
		$this->noCacheHeaders();
		header('Content-Type: application/json');

		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['feed_id'])) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
			exit;
		}

		$feedId = Minz_Request::paramInt('feed_id');
		if ($feedId <= 0) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => 'Invalid feed ID']);
			exit;
		}

		try {
			$entryDAO = FreshRSS_Factory::createEntryDAO();
			$idMax = uTimeString(); // Current timestamp
			$affected = $entryDAO->markReadFeed($feedId, $idMax);

			if ($affected !== false) {
				echo json_encode(['status' => 'success', 'affected' => $affected]);
			} else {
				http_response_code(500);
				echo json_encode(['status' => 'error', 'message' => 'Failed to mark feed as read']);
			}
		} catch (Exception $e) {
			http_response_code(500);
			echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
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
		$this->noCacheHeaders();
		header('Content-Type: application/json');

		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['tab_id'])) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
			exit;
		}

		$tabId = Minz_Request::paramString('tab_id');

		try {
			$layout = $this->getLayout();
			$entryDAO = FreshRSS_Factory::createEntryDAO();
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
		$this->noCacheHeaders();
		header('Content-Type: application/json');

		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
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
			!in_array($fontSize, FreshVibesViewExtension::ALLOWED_FONT_SIZES) ||
			!$isValidMaxHeight ||
			!in_array($displayMode, FreshVibesViewExtension::ALLOWED_DISPLAY_MODES)
		) {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => 'Invalid settings']);
			exit;
		}

		try {
			$userConf = FreshRSS_Context::userConf();
			$mode = $this->getMode();
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
					$userConf->_attribute($headerPrefix . $feedId, $headerColor);
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
		$this->noCacheHeaders();
		header('Content-Type: application/json');

		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			http_response_code(400);
			echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
			exit;
		}

		$numColumns = Minz_Request::paramInt('num_columns', FreshVibesViewExtension::DEFAULT_TAB_COLUMNS);
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
			$mode = $this->getMode();

			foreach ($layout as &$tab) {
				$tab['num_columns'] = $numColumns;
				$allFeeds = array_merge(...array_values($tab['columns']));
				$newColumns = $this->buildEmptyColumns($numColumns);
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

				if ($bgColor) {
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
		$this->noCacheHeaders();
		header('Content-Type: application/json');

		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			http_response_code(400);
			exit;
		}

		try {
			$userConf = FreshRSS_Context::userConf();
			$mode = $this->getMode();
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

				$keys = [
					$limitPrefix . $feedId,
					$fontPrefix . $feedId,
					$headerPrefix . $feedId,
					$maxHeightPrefix . $feedId,
					$displayModePrefix . $feedId,
				];
				foreach ($keys as $key) {
					if (method_exists($userConf, 'removeAttribute')) {
						$userConf->removeAttribute($key);
					} else {
						unset($userConf->$key);
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

	public function resetAllTabSettingsAction() {
		$this->noCacheHeaders();
		header('Content-Type: application/json');

		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			http_response_code(400);
			exit;
		}

		try {
			$layout = $this->getLayout();
			$userConf = FreshRSS_Context::userConf();
			$mode = $this->getMode();

			foreach ($layout as &$tab) {
				$tab['num_columns'] = FreshVibesViewExtension::DEFAULT_TAB_COLUMNS;
				$allFeeds = array_merge(...array_values($tab['columns']));
				$newColumns = $this->buildEmptyColumns(FreshVibesViewExtension::DEFAULT_TAB_COLUMNS);
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
}
