<?php

declare(strict_types=1);

class FreshVibesViewExtension extends Minz_Extension
{

	// --- Constants ---
	public const CONTROLLER_NAME_BASE = 'freshvibes';
	public const EXT_ID = 'FreshVibesView';
	// Config Keys
	public const LAYOUT_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_layout';
	public const ACTIVE_TAB_CONFIG_KEY = self::CONTROLLER_NAME_BASE . '_active_tab';
	public const LIMIT_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_limit_feedid_';
	public const FONT_SIZE_CONFIG_PREFIX = self::CONTROLLER_NAME_BASE . '_fontsize_feedid_';
	public const REFRESH_ENABLED_CONFIG_KEY = 'freshvibes_refresh_enabled';
	public const REFRESH_INTERVAL_CONFIG_KEY = 'freshvibes_refresh_interval';
	public const DATE_FORMAT_CONFIG_KEY = 'freshvibes_date_format';
	public const DEFAULT_TAB_COLUMNS = 3;
	// Feed Limits
	public const DEFAULT_ARTICLES_PER_FEED = 10;
	public const ALLOWED_LIMIT_VALUES = [5, 10, 15, 20, 25, 30, 40, 50];
	// Font Sizes
	public const ALLOWED_FONT_SIZES = ['small', 'regular', 'large'];
	public const DEFAULT_FONT_SIZE = 'regular';
	// --- End Constants ---

	public function getId(): string
	{
		return self::EXT_ID;
	}

	public function init(): void
	{
		$this->registerTranslates();
		$this->registerController(self::CONTROLLER_NAME_BASE);
		$this->registerViews();
		$this->registerHook('nav_reading_modes', [self::class, 'addReadingMode']);

		Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
		Minz_View::appendScript($this->getFileUrl('Sortable.min.js', 'js'), false, true, false);
		Minz_View::appendScript($this->getFileUrl('script.js', 'js'), false, true, false);
	}

	/** Hook callback to register the view as a reading mode. */
	public static function addReadingMode(array $readingModes): array
	{
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
	public function handleConfigureAction(): void
	{
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			$userConf = FreshRSS_Context::userConf();

			$userConf->_attribute(self::REFRESH_ENABLED_CONFIG_KEY, Minz_Request::paramBoolean('freshvibes_refresh_enabled') ? 1 : 0);
			$userConf->_attribute(self::REFRESH_INTERVAL_CONFIG_KEY, Minz_Request::paramInt('freshvibes_refresh_interval', 15));
			$userConf->_attribute(self::DATE_FORMAT_CONFIG_KEY, Minz_Request::paramString('freshvibes_date_format') ?: 'Y-m-d H:i');

			$userConf->save();
		}
	}
	/**
	 * A helper to get a specific setting's value for this extension.
	 * @param string $key The setting key.
	 * @param mixed $default The default value to return if not set.
	 * @return mixed The setting value.
	 */
	public function getSetting(string $key, $default = null)
	{
		$userConf = FreshRSS_Context::userConf();
		if (!$userConf->hasParam($key)) {
			return $default;
		}

		switch ($key) {
			case self::REFRESH_ENABLED_CONFIG_KEY:
				return (bool)$userConf->attributeInt($key);
			case self::REFRESH_INTERVAL_CONFIG_KEY:
				return $userConf->attributeInt($key) ?? $default;
			case self::DATE_FORMAT_CONFIG_KEY:
				return $userConf->attributeString($key) ?? $default;
			default:
				return $default;
		}
	}

	public function cspRules(): array {
		$host = parse_url(Minz_Url::base(), PHP_URL_HOST);
		return [
			'connect-src' => [
				$host,
			],
		];
	}
}
