<?php

declare(strict_types=1);

class FreshExtension_freshvibes_Controller extends Minz_ActionController
{

	private function noCacheHeaders()
	{
		header('Cache-Control: no-cache, no-store, must-revalidate');
		header('Pragma: no-cache');
		header('Expires: 0');
	}

	private function getLayout(): array
	{
		$userConf = FreshRSS_Context::userConf();
		$layoutKey = FreshVibesViewExtension::LAYOUT_CONFIG_KEY;
		$layout = $userConf->hasParam($layoutKey)
			? $userConf->param($layoutKey)
			: null;

		if ($layout === null) {
			$numCols = FreshVibesViewExtension::DEFAULT_TAB_COLUMNS;
			$columns = $this->buildEmptyColumns($numCols);

			$feedDAO = new FreshRSS_FeedDAO();
			$feeds = $feedDAO->listFeeds();
			$i = 0;
			foreach ($feeds as $feed) {
				$colKey = 'col' . (($i % $numCols) + 1);
				$columns[$colKey][] = $feed->id();
				$i++;
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
		return is_array($layout) ? $layout : [];
	}

	private function saveLayout(array $layout): void
	{
		$userConf = FreshRSS_Context::userConf();
		$userConf->_attribute(FreshVibesViewExtension::LAYOUT_CONFIG_KEY, $layout);
		$userConf->save();
	}

	public function indexAction()
	{
		$this->noCacheHeaders();
		$feedDAO = new FreshRSS_FeedDAO();
		$entryDAO = new FreshRSS_EntryDAO();
		try {
			FreshRSS_Context::updateUsingRequest(true);
		} catch (FreshRSS_Context_Exception $e) {
			Minz_Error::error(404);
			return;
		}

		$feeds = $feedDAO->listFeeds();
		$userConf = FreshRSS_Context::userConf();
		// FIX: Access 'extensions' as an array and provide a default to prevent errors
		$extConf = $userConf->extensions[FreshVibesViewExtension::EXT_ID] ?? new stdClass();
		$stateAll = defined('FreshRSS_Entry::STATE_ALL') ? FreshRSS_Entry::STATE_ALL : 0;
		$feedsData = [];
		$dateFormat = $userConf->param(FreshVibesViewExtension::DATE_FORMAT_CONFIG_KEY, 'Y-m-d H:i');

		foreach ($feeds as $feed) {
			$entries = [];
			$feedId = $feed->id();
			$limitKey = FreshVibesViewExtension::LIMIT_CONFIG_PREFIX . $feedId;
			$fontSizeKey = FreshVibesViewExtension::FONT_SIZE_CONFIG_PREFIX . $feedId;

			if ($userConf->hasParam($limitKey)) {
				$limit = $userConf->attributeInt($limitKey);
			} else {
				$limit = FreshVibesViewExtension::DEFAULT_ARTICLES_PER_FEED;
			}
			if (!in_array($limit, FreshVibesViewExtension::ALLOWED_LIMIT_VALUES, true)) {
				$limit = FreshVibesViewExtension::DEFAULT_ARTICLES_PER_FEED;
			}


			if ($userConf->hasParam($fontSizeKey)) {
				$fontSize = $userConf->attributeString($fontSizeKey);
			} else {
				$fontSize = FreshVibesViewExtension::DEFAULT_FONT_SIZE;
			}
			if (!in_array($fontSize, FreshVibesViewExtension::ALLOWED_FONT_SIZES)) {
				$fontSize = FreshVibesViewExtension::DEFAULT_FONT_SIZE;
			}

			try {
				$entryGenerator = $entryDAO->listWhere('f', $feedId, $stateAll, null, '0', '0', 'date', 'DESC', '0', 0, 0, 0, true);
				$fetchedEntries = [];
				$fetchedCount = 0;

				$processEntry = function (FreshRSS_Entry $entry) use (&$fetchedEntries, $dateFormat) {
					$fetchedEntries[] = [
						'link' => $entry->link(),
						'title' => $entry->title(),
						'dateShort' => date($dateFormat, $entry->date(true)),
						'dateFull' => (string) $entry->date(true),
						'snippet' => $this->generateSnippet($entry)
					];
				};

				foreach ($entryGenerator as $entry) {
					if ($entry instanceof FreshRSS_Entry) {
						$processEntry($entry);
						$fetchedCount++;
						if ($fetchedCount >= $limit) {
							break; // Stop after fetching the desired limit
						}
					}
				}
				$entries = $fetchedEntries;
			} catch (Exception $e) {
				$entries = ['error' => 'Error loading entries (' . $feedId . ')'];
			}

			$feedsData[$feedId] = [
				'id' => $feedId,
				'name' => $feed->name(),
				'favicon' => $feed->favicon(),
				'website' => $feed->website(),
				'entries' => $entries,
				'currentLimit' => $limit,
				'currentFontSize' => $fontSize,
			];
		}

		$controllerParam = strtolower(FreshVibesViewExtension::CONTROLLER_NAME_BASE);
		@$this->view->feedsData = $feedsData;
		@$this->view->getLayoutUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'getlayout'], 'json', true);
		@$this->view->saveLayoutUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'savelayout'], 'json', true);
		@$this->view->saveFeedSettingsUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'savefeedsettings'], 'json', true);
		@$this->view->tabActionUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'updatetab'], 'json', true);
		@$this->view->moveFeedUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'movefeed'], 'json', true);
		@$this->view->setActiveTabUrl = Minz_Url::display(['c' => $controllerParam, 'a' => 'setactivetab'], 'json', true);
		@$this->view->rss_title = _t('ext.FreshVibesView.title');
		@$this->view->refreshEnabled = (bool)$userConf->param(FreshVibesViewExtension::REFRESH_ENABLED_CONFIG_KEY, 0);
		@$this->view->refreshInterval = (int)$userConf->param(FreshVibesViewExtension::REFRESH_INTERVAL_CONFIG_KEY, 15);
		@$this->view->html_url = Minz_Url::display(['c' => $controllerParam, 'a' => 'index']);

		@$this->view->categories = FreshRSS_Context::categories();
		$tags = FreshRSS_Context::labels(true);
		@$this->view->tags = $tags;
		$nbUnreadTags = 0;
		foreach ($tags as $tag) {
			$nbUnreadTags += $tag->nbUnread();
		}
		@$this->view->nbUnreadTags = $nbUnreadTags;

		$this->view->_path(FreshVibesViewExtension::CONTROLLER_NAME_BASE . '/index.phtml');
	}

	public function getLayoutAction()
	{
		$this->noCacheHeaders();
		header('Content-Type: application/json');
		try {
			$layout = $this->getLayout();
			$userConf = FreshRSS_Context::userConf();
			$activeTabKey = FreshVibesViewExtension::ACTIVE_TAB_CONFIG_KEY;
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
			echo json_encode(['layout' => $layout, 'active_tab_id' => $activeTabId]);
		} catch (Exception $e) {
			http_response_code(500);
			echo json_encode(['error' => 'Server error loading layout.']);
		}
		exit;
	}

	public function saveLayoutAction()
	{
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

	public function updateTabAction()
	{
		$this->noCacheHeaders();
		header('Content-Type: application/json');
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['operation'])) {
			http_response_code(400);
			exit;
		}
		$operation = Minz_Request::paramString('operation');
		$layout = $this->getLayout();
		try {
			switch ($operation) {
				case 'add':
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
					$tabId = Minz_Request::paramString('tab_id');
					if (count($layout) <= 1) {
						throw new Exception('Cannot delete the last tab.');
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
						if (!empty($feedsToMove)) {
							$firstColKey = key($layout[0]['columns']);
							$layout[0]['columns'][$firstColKey] = array_unique(array_merge($layout[0]['columns'][$firstColKey], $feedsToMove));
						}
					}
					$this->saveLayout($layout);
					echo json_encode(['status' => 'success', 'deleted_tab_id' => $tabId, 'new_layout' => $layout]);
					break;
				case 'rename':
					$tabId = Minz_Request::paramString('tab_id');
					$newName = trim(Minz_Request::paramString('value'));
					if (empty($newName)) {
						throw new Exception('Tab name cannot be empty.');
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
				default:
					throw new Exception('Unknown tab operation.');
			}
		} catch (Exception $e) {
			http_response_code(500);
			echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
		}
		exit;
	}

	private function buildEmptyColumns(int $count): array
	{
		$columns = [];
		for ($i = 1; $i <= $count; $i++) {
			$columns['col' . $i] = [];
		}
		return $columns;
	}

	public function setActiveTabAction()
	{
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['tab_id'])) {
			http_response_code(400);
			exit;
		}
		try {
			FreshRSS_Context::userConf()->_attribute(FreshVibesViewExtension::ACTIVE_TAB_CONFIG_KEY, $_POST['tab_id']);
			FreshRSS_Context::userConf()->save();
			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			http_response_code(500);
		}
		exit;
	}

	public function saveFeedSettingsAction()
	{
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['feed_id']) || !isset($_POST['limit']) || !isset($_POST['font_size'])) {
			http_response_code(400);
			exit;
		}
		$feedId = Minz_Request::paramInt('feed_id');
		$limit = Minz_Request::paramInt('limit');
		$fontSize = Minz_Request::paramString('font_size');
		if ($feedId <= 0 || !in_array($limit, FreshVibesViewExtension::ALLOWED_LIMIT_VALUES, true) || !in_array($fontSize, FreshVibesViewExtension::ALLOWED_FONT_SIZES)) {
			http_response_code(400);
			exit;
		}
		try {
			$userConf = FreshRSS_Context::userConf();
			$userConf->_attribute(FreshVibesViewExtension::LIMIT_CONFIG_PREFIX . $feedId, $limit);
			$userConf->_attribute(FreshVibesViewExtension::FONT_SIZE_CONFIG_PREFIX . $feedId, $fontSize);
			$userConf->save();
			echo json_encode(['status' => 'success']);
		} catch (Exception $e) {
			http_response_code(500);
		}
		exit;
	}

	public function moveFeedAction()
	{
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

	private function generateSnippet(FreshRSS_Entry $entry, int $wordLimit = 15): string
	{
		$content = $entry->content() ?? '';
		$plainText = trim(strip_tags(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
		if (empty($plainText)) {
			return '';
		}
		$words = preg_split('/[\s,]+/', $plainText, $wordLimit + 1);
		return count($words) > $wordLimit ? implode(' ', array_slice($words, 0, $wordLimit)) . '…' : implode(' ', $words);
	}
}
