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

	public const DATE_MODE_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_date_mode';
	public const DATE_MODES = ['absolute', 'relative'];
	public const DEFAULT_DATE_MODE = 'absolute';

	public const FEED_DISPLAY_MODE_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_display_mode_feedid_';
	public const CATEGORY_FEED_DISPLAY_MODE_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_category_display_mode_feedid_';
	public const ALLOWED_DISPLAY_MODES = ['tiny', 'compact', 'detailed'];
	public const DEFAULT_DISPLAY_MODE = 'tiny';
	public const BULK_SETTINGS_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_bulk_settings';
	public const CONFIRM_MARK_READ_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_confirm_mark_read';
	public const NEW_FEED_POSITION_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_new_feed_position';
	public const NEW_FEED_POSITIONS = ['bottom', 'top'];
	public const DEFAULT_NEW_FEED_POSITION = 'bottom';

	// Feed Limits
	public const DEFAULT_ARTICLES_PER_FEED = 10;
	public const ALLOWED_LIMIT_VALUES = [5, 10, 15, 20, 25, 30, 40, 50, 'unlimited'];

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

	public function init(): void {
		$this->registerTranslates();
		$this->registerController(self::CONTROLLER_NAME_BASE);
		$this->registerViews();
		$this->registerHook('nav_reading_modes', [self::class, 'addReadingMode']);
		$this->registerHook('view_modes', [self::class, 'addViewMode']);

		// @phpstan-ignore-next-line
		Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
		// @phpstan-ignore-next-line
		Minz_View::appendScript($this->getFileUrl('Sortable.min.js', 'js'), false, true, false);
		// @phpstan-ignore-next-line
		Minz_View::appendScript($this->getFileUrl('script.js', 'js'), false, true, false);
	}

	public function autoload(string $class_name): void {
		if (str_starts_with($class_name, 'tryallthethings\\FreshVibes\\')) {
			$class_name = substr($class_name, strlen('tryallthethings\\FreshVibes\\'));
			$base_path = $this->getPath() . '/';
			include($base_path . str_replace('\\', '/', $class_name) . '.php');
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
			$userConf->_attribute(self::REFRESH_INTERVAL_CONFIG_KEY, Minz_Request::paramInt('freshvibes_refresh_interval') ?: 15);
			$userConf->_attribute(self::DATE_FORMAT_CONFIG_KEY, Minz_Request::paramString('freshvibes_date_format') ?: 'Y-m-d H:i');
			$mode = Minz_Request::paramStringNull('freshvibes_view_mode') ?? 'custom';
			$userConf->_attribute(self::MODE_CONFIG_KEY, $mode === 'categories' ? 'categories' : 'custom');
			$userConf->_attribute(self::HIDE_SIDEBAR_CONFIG_KEY, Minz_Request::paramBoolean('freshvibes_hide_sidebar'));
			$userConf->_attribute(self::HIDE_SUBSCRIPTION_CONTROL_CONFIG_KEY, Minz_Request::paramBoolean('freshvibes_hide_subscription_control'));
			$userConf->_attribute(self::CONFIRM_TAB_DELETE_CONFIG_KEY, Minz_Request::paramBoolean('freshvibes_confirm_tab_delete'));
			$userConf->_attribute(self::ENTRY_CLICK_MODE_CONFIG_KEY, Minz_Request::paramStringNull('freshvibes_entry_click_mode') ?? 'modal');
			$userConf->_attribute(self::DATE_MODE_CONFIG_KEY, Minz_Request::paramString('freshvibes_date_mode') ?: 'absolute');
			$userConf->_attribute(self::CONFIRM_MARK_READ_CONFIG_KEY, Minz_Request::paramBoolean('freshvibes_confirm_mark_read'));
			$userConf->_attribute(self::NEW_FEED_POSITION_CONFIG_KEY, Minz_Request::paramString('freshvibes_new_feed_position') ?: 'bottom');

			$userConf->save();
		}
	}
}
