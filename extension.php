<?php

declare(strict_types=1);

class FreshVibesViewExtension extends Minz_Extension {

	// --- Constants ---
	public const CONTROLLER_NAME_BASE = 'freshvibes';
	public const EXT_ID = 'FreshVibesView';
	// Config Keys
	public const LAYOUT_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_layout';
	public const ACTIVE_TAB_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_active_tab';
	public const LIMIT_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_limit_feedid_';
	public const FONT_SIZE_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_fontsize_feedid_';
	// Feed Limits
	public const DEFAULT_ARTICLES_PER_FEED = 10;
	public const ALLOWED_LIMIT_VALUES = [5, 10, 15, 20, 25, 30, 40, 50];
	// Font Sizes
	public const ALLOWED_FONT_SIZES = ['small', 'regular', 'large'];
	public const DEFAULT_FONT_SIZE = 'regular';
	// --- End Constants ---

    public function getId(): string {
        return self::EXT_ID;
    }

	public function init(): void {
		$this->registerTranslates();
		$this->registerController(self::CONTROLLER_NAME_BASE);
		$this->registerViews();
		$this->registerHook('nav_reading_modes', [self::class, 'addReadingMode']);

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

     /**
	 * Handles the logic when the configuration form is submitted.
	 */
	 #[\Override]
	public function handleConfigureAction(): void {
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			$userConf = FreshRSS_Context::userConf();
			
			$userConf->_attribute('refresh_enabled', Minz_Request::paramBoolean('refresh_enabled') ? 1 : 0);
			$userConf->_attribute('refresh_interval', Minz_Request::paramInt('refresh_interval', 15));
			$userConf->_attribute('date_format', Minz_Request::paramString('date_format') ?: 'Y-m-d H:i');
			
			$userConf->save();
		}
	}
	/**
	 * A helper to get a specific setting's value for this extension.
	 * @param string $key The setting key.
	 * @param mixed $default The default value to return if not set.
	 * @return mixed The setting value.
	 */
    public function getSetting(string $key, $default = null) {
        $userConf = FreshRSS_Context::userConf();
        
        switch ($key) {
            case 'refresh_enabled':
                $value = $userConf->attributeInt($key);
                return $value === null ? $default : (bool)$value;
            case 'refresh_interval':
                return $userConf->attributeInt($key) ?? $default;
            case 'date_format':
                return $userConf->attributeString($key) ?? $default;
            default:
                return $default;
        }
    }
}