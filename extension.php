<?php

declare(strict_types=1);

class FreshVibesViewExtension extends Minz_Extension {
	protected array $csp_policies = [
		'connect-src' => "'self'",
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

	// Feed Limits
	public const DEFAULT_ARTICLES_PER_FEED = 10;
	public const ALLOWED_LIMIT_VALUES = [5, 10, 15, 20, 25, 30, 40, 50, 'unlimited'];

	// Font Sizes
	public const ALLOWED_FONT_SIZES = ['xsmall', 'small', 'regular', 'large', 'xlarge'];
	public const DEFAULT_FONT_SIZE = 'regular';
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

	public function init(): void {
		$this->registerTranslates();
		$this->registerController(self::CONTROLLER_NAME_BASE);
		$this->registerViews();
		$this->registerHook('nav_reading_modes', [self::class, 'addReadingMode']);
		$this->registerHook('view_modes', [self::class, 'addViewMode']);

		Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
		Minz_View::appendScript($this->getFileUrl('Sortable.min.js', 'js'), false, true, false);
		Minz_View::appendScript($this->getFileUrl('script.js', 'js'), false, true, false);
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
		$mode->setName('📊');
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

			$userConf->_attribute(self::REFRESH_ENABLED_CONFIG_KEY, Minz_Request::paramBoolean('freshvibes_refresh_enabled') ? 1 : 0);
			$userConf->_attribute(self::REFRESH_INTERVAL_CONFIG_KEY, Minz_Request::paramInt('freshvibes_refresh_interval', 15));
			$userConf->_attribute(self::DATE_FORMAT_CONFIG_KEY, Minz_Request::paramString('freshvibes_date_format') ?: 'Y-m-d H:i');
			$mode = Minz_Request::paramStringNull('freshvibes_view_mode') ?? 'custom';
			$userConf->_attribute(self::MODE_CONFIG_KEY, $mode === 'categories' ? 'categories' : 'custom');
			$userConf->_attribute(self::HIDE_SIDEBAR_CONFIG_KEY, Minz_Request::paramBoolean('freshvibes_hide_sidebar') ? 1 : 0);
			$userConf->_attribute(self::HIDE_SUBSCRIPTION_CONTROL_CONFIG_KEY, Minz_Request::paramBoolean('freshvibes_hide_subscription_control') ? 1 : 0);
			$userConf->_attribute(self::CONFIRM_TAB_DELETE_CONFIG_KEY, Minz_Request::paramBoolean('freshvibes_confirm_tab_delete') ? 1 : 0);
			$userConf->_attribute(self::ENTRY_CLICK_MODE_CONFIG_KEY, Minz_Request::paramStringNull('freshvibes_entry_click_mode') ?? 'modal');

			$userConf->save();
		}
	}

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


	/**
	 * A helper to get a specific setting's value for this extension.
	 * @param string $key The setting key.
	 * @param mixed $default The default value to return if not set.
	 * @return mixed The setting value.
	 */
	public function getSetting(string $key, $default = null) {
		$userConf = FreshRSS_Context::userConf();
		if (!$userConf->hasParam($key)) {
			return $default;
		}

		switch ($key) {
			case self::CONFIRM_TAB_DELETE_CONFIG_KEY:
			case self::HIDE_SIDEBAR_CONFIG_KEY:
			case self::REFRESH_ENABLED_CONFIG_KEY:
			case self::HIDE_SUBSCRIPTION_CONTROL_CONFIG_KEY:
				return (bool)$userConf->attributeInt($key);
			case self::REFRESH_INTERVAL_CONFIG_KEY:
				return $userConf->attributeInt($key) ?? $default;
			case self::DATE_FORMAT_CONFIG_KEY:
			case self::ENTRY_CLICK_MODE_CONFIG_KEY:
				return $userConf->attributeString($key) ?? $default;
			case self::MODE_CONFIG_KEY:
				return $userConf->attributeString($key) ?? $default;
			default:
				return $default;
		}
	}
}
