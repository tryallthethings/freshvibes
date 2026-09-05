<?php

declare(strict_types=1);

class FreshVibesViewExtension extends Minz_Extension {
	protected array $csp_policies = [
		'connect-src' => "'self'",
		// Secondary containment for injected markup. Neither directive falls back to `default-src`,
		// and FreshRSS uses no <object>/<embed> or <base>, so both are safe to declare globally.
		// https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy
		'object-src' => "'none'",
		'base-uri' => "'self'",
	];

	// --- Constants ---
	public const CONTROLLER_NAME_BASE = 'freshvibes';
	public const EXT_ID = 'FreshVibesView';
	// Config Keys
	public const LAYOUT_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_layout';
	public const CATEGORY_LAYOUT_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_category_layout';
	public const MODE_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_mode';
	public const ACTIVE_TAB_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_active_tab';
	public const ACTIVE_TAB_CATEGORY_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_category_active_tab';
	public const LIMIT_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_limit_feedid_';
	public const CATEGORY_LIMIT_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_category_limit_feedid_';
	public const FONT_SIZE_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_fontsize_feedid_';
	public const CATEGORY_FONT_SIZE_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_category_fontsize_feedid_';
	public const REFRESH_ENABLED_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_refresh_enabled';
	public const REFRESH_INTERVAL_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_refresh_interval';
	public const DATE_FORMAT_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_date_format';
	public const DEFAULT_TAB_COLUMNS = 3;
	public const HIDE_SIDEBAR_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_hide_sidebar';
	public const HIDE_SUBSCRIPTION_CONTROL_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_hide_subscription_control';
	public const CONFIRM_TAB_DELETE_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_confirm_tab_delete';
	public const ENTRY_CLICK_MODE_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_entry_click_mode';
	public const ENTRY_CLICK_MODES = ['modal', 'external'];

	public const CATEGORY_MAX_HEIGHT_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_category_feed_max_height';
	public const MAX_HEIGHT_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_feed_max_height';
	public const ALLOWED_MAX_HEIGHTS_CONFIG_KEY = ['300', '400', '500', '600', '700', '800', 'unlimited', 'fit'];
	public const DEFAULT_MAX_HEIGHT_CONFIG_KEY = 'fit';

	public const DATE_MODE_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_date_mode';
	public const DATE_MODES = ['absolute', 'relative'];
	public const DEFAULT_DATE_MODE = 'absolute';

	public const FEED_DISPLAY_MODE_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_display_mode_feedid_';
	public const CATEGORY_FEED_DISPLAY_MODE_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_category_display_mode_feedid_';
	public const ALLOWED_DISPLAY_MODES = ['tiny', 'compact', 'detailed'];
	public const DEFAULT_DISPLAY_MODE = 'tiny';
	public const CONFIRM_MARK_READ_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_confirm_mark_read';
	public const NEW_FEED_POSITION_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_new_feed_position';
	public const NEW_FEED_POSITIONS = ['bottom', 'top'];
	public const DEFAULT_NEW_FEED_POSITION = 'bottom';
	public const ANIMATIONS_ENABLED_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_animations_enabled';
	public const EMPTY_FEEDS_DISPLAY_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_empty_feeds_display';
	public const EMPTY_FEEDS_DISPLAY_OPTIONS = ['show', 'collapse_content', 'hide_completely'];
	public const DEFAULT_EMPTY_FEEDS_DISPLAY = 'show';
	public const DASHBOARD_LAYOUT_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_dashboard_layout';
	public const DASHBOARD_LAYOUT_OPTIONS = ['tabs', 'vertical'];
	public const DEFAULT_DASHBOARD_LAYOUT = 'tabs';
	public const ALLOW_CATEGORY_SORT_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_allow_category_sort';

	// Feed Limits
	public const DEFAULT_ARTICLES_PER_FEED = 10;
	public const ALLOWED_LIMIT_VALUES = [5, 10, 15, 20, 25, 30, 40, 50, 'unlimited'];

	/**
	 * Hard ceiling applied to the per-feed query, including for feeds configured as `unlimited`.
	 * Without it a single dashboard request can materialise every stored entry of every feed:
	 * FreshRSS only emits a SQL LIMIT when the value is greater than zero.
	 */
	public const MAX_ENTRIES_CAP_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_max_entries_cap';
	public const DEFAULT_MAX_ENTRIES_CAP = 500;
	public const MIN_MAX_ENTRIES_CAP = 50;
	public const MAX_MAX_ENTRIES_CAP = 5000;

	// Bounds for free-form configuration values.
	public const MIN_REFRESH_INTERVAL = 1;
	public const MAX_REFRESH_INTERVAL = 1440;
	public const DEFAULT_REFRESH_INTERVAL = 15;
	public const DEFAULT_DATE_FORMAT = 'Y-m-d H:i';
	public const MAX_DATE_FORMAT_LENGTH = 64;

	// Font Sizes
	public const ALLOWED_FONT_SIZES = ['xsmall', 'small', 'regular', 'large', 'xlarge'];
	public const DEFAULT_FONT_SIZE = 'regular';

	// Config Prefixes
	public const TAB_BG_COLOR_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_tab_bgcolor_';
	public const CATEGORY_TAB_BG_COLOR_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_category_tab_bgcolor_';
	public const TAB_FONT_COLOR_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_tab_fontcolor_';
	public const CATEGORY_TAB_FONT_COLOR_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_category_tab_fontcolor_';
	public const FEED_HEADER_COLOR_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_feed_headercolor_';
	public const CATEGORY_FEED_HEADER_COLOR_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_category_feed_headercolor_';
	// --- End Constants ---

	public function getId(): string {
		return self::EXT_ID;
	}

	#[\Override]
	public function init(): void {
		$this->registerTranslates();
		$this->registerController(self::CONTROLLER_NAME_BASE);
		$this->registerViews();
		$this->registerHook('nav_reading_modes', [self::class, 'addReadingMode']);
		$this->registerHook('view_modes', [self::class, 'addViewMode']);

		Minz_View::appendStyle($this->getFileUrl('style.css'));
		Minz_View::appendScript($this->getFileUrl('Sortable.min.js'), false, true, false);
		Minz_View::appendScript($this->getFileUrl('script.js'), false, true, false);
	}

	public function autoload(string $class_name): void {
		if (str_starts_with($class_name, 'tryallthethings\\FreshVibes\\')) {
			$class_name = substr($class_name, strlen('tryallthethings\\FreshVibes\\'));
			$base_path = $this->getPath() . '/';
			include($base_path . str_replace('\\', '/', $class_name) . '.php');
		}
	}

	#[\Override]
	public function uninstall() {
		$userConf = FreshRSS_Context::userConf();

		// Only change the view_mode if it's currently set to this extension's view
		if ($userConf->hasParam('view_mode') && $userConf->view_mode === self::CONTROLLER_NAME_BASE) {
			$userConf->_attribute('view_mode', 'normal');
			$userConf->save();
		}

		// The uninstall method must return true on success.
		return true;
	}

	/** Hook callback to register the view as a reading mode. */
	public static function addReadingMode(array $readingModes): array {
		$urlParams = array_merge(Minz_Request::currentRequest(), [
			'c' => self::CONTROLLER_NAME_BASE,
			'a' => 'index',
		]);
		$isActive = Minz_Request::controllerName() === self::CONTROLLER_NAME_BASE
			&& Minz_Request::actionName() === 'index';

		$mode = new FreshRSS_ReadingMode(
			'view-freshvibes',
			_t('ext.' . self::EXT_ID . '.title'),
			$urlParams,
			$isActive
		);

		$icon_path = __DIR__ . '/img/freshvibes.svg';

		if (is_readable($icon_path)) {
			$icon_html = file_get_contents($icon_path);
			$icon_html = str_replace('<svg', '<svg class="icon"', $icon_html);
		} else {
			// Fallback text if the icon cannot be read
			$icon_html = '📊';
		}

		$mode->setName($icon_html);
		$readingModes[] = $mode;
		return $readingModes;
	}

	public static function addViewMode(array $modes): array {
		$modes[] = new FreshRSS_ViewMode(
			self::CONTROLLER_NAME_BASE,
			_t('ext.' . self::EXT_ID . '.title'),
			self::CONTROLLER_NAME_BASE,
			'index'
		);
		return $modes;
	}

	/**
	 * Handles the logic when the configuration form is submitted.
	 */
	#[\Override]
	public function handleConfigureAction(): void {
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			$userConf = FreshRSS_Context::userConf();

			$userConf->_attribute(self::REFRESH_ENABLED_CONFIG_KEY, Minz_Request::paramBoolean('freshvibes_refresh_enabled'));

			// Bound the interval so a crafted or mistyped value cannot schedule a runaway poll.
			$refreshInterval = Minz_Request::paramInt('freshvibes_refresh_interval') ?: self::DEFAULT_REFRESH_INTERVAL;
			$refreshInterval = max(self::MIN_REFRESH_INTERVAL, min(self::MAX_REFRESH_INTERVAL, $refreshInterval));
			$userConf->_attribute(self::REFRESH_INTERVAL_CONFIG_KEY, $refreshInterval);

			$dateFormat = trim(Minz_Request::paramString('freshvibes_date_format'));
			if ($dateFormat === '' || mb_strlen($dateFormat) > self::MAX_DATE_FORMAT_LENGTH) {
				$dateFormat = self::DEFAULT_DATE_FORMAT;
			}
			$userConf->_attribute(self::DATE_FORMAT_CONFIG_KEY, $dateFormat);

			$entriesCap = Minz_Request::paramInt('freshvibes_max_entries_cap') ?: self::DEFAULT_MAX_ENTRIES_CAP;
			$entriesCap = max(self::MIN_MAX_ENTRIES_CAP, min(self::MAX_MAX_ENTRIES_CAP, $entriesCap));
			$userConf->_attribute(self::MAX_ENTRIES_CAP_CONFIG_KEY, $entriesCap);

			$mode = Minz_Request::paramStringNull('freshvibes_view_mode') ?? 'custom';
			$userConf->_attribute(self::MODE_CONFIG_KEY, $mode === 'categories' ? 'categories' : 'custom');
			$userConf->_attribute(self::HIDE_SIDEBAR_CONFIG_KEY, Minz_Request::paramBoolean('freshvibes_hide_sidebar'));
			$userConf->_attribute(self::HIDE_SUBSCRIPTION_CONTROL_CONFIG_KEY, Minz_Request::paramBoolean('freshvibes_hide_subscription_control'));
			$userConf->_attribute(self::CONFIRM_TAB_DELETE_CONFIG_KEY, Minz_Request::paramBoolean('freshvibes_confirm_tab_delete'));
			$userConf->_attribute(self::CONFIRM_MARK_READ_CONFIG_KEY, Minz_Request::paramBoolean('freshvibes_confirm_mark_read'));
			$userConf->_attribute(self::ANIMATIONS_ENABLED_CONFIG_KEY, Minz_Request::paramBoolean('freshvibes_animations_enabled'));
			$userConf->_attribute(self::ALLOW_CATEGORY_SORT_CONFIG_KEY, Minz_Request::paramBoolean('freshvibes_allow_category_sort'));

			// Every enumerated setting is checked against its declared allow-list before being stored,
			// so an unexpected value can never reach the renderer or the saved configuration.
			$enums = [
				self::ENTRY_CLICK_MODE_CONFIG_KEY => ['freshvibes_entry_click_mode', self::ENTRY_CLICK_MODES, 'modal'],
				self::DATE_MODE_CONFIG_KEY => ['freshvibes_date_mode', self::DATE_MODES, self::DEFAULT_DATE_MODE],
				self::NEW_FEED_POSITION_CONFIG_KEY => ['freshvibes_new_feed_position', self::NEW_FEED_POSITIONS, self::DEFAULT_NEW_FEED_POSITION],
				self::EMPTY_FEEDS_DISPLAY_CONFIG_KEY => [
					'freshvibes_empty_feeds_display', self::EMPTY_FEEDS_DISPLAY_OPTIONS, self::DEFAULT_EMPTY_FEEDS_DISPLAY,
				],
				self::DASHBOARD_LAYOUT_CONFIG_KEY => [
					'freshvibes_dashboard_layout', self::DASHBOARD_LAYOUT_OPTIONS, self::DEFAULT_DASHBOARD_LAYOUT,
				],
			];
			foreach ($enums as $configKey => [$param, $allowed, $default]) {
				$value = Minz_Request::paramString($param);
				$userConf->_attribute($configKey, in_array($value, $allowed, true) ? $value : $default);
			}

			$userConf->save();
		}
	}
}
