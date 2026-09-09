<?php

declare(strict_types=1);

namespace tryallthethings\FreshVibes\Tests;

use FreshRSS_Context;
use FreshVibesViewExtension;
use Minz_Exception;
use Minz_Log;
use Minz_Request;
use ConfigurationDouble;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the extension entrypoint (FV-005).
 *
 * The configuration form and the uninstall hook both call `Minz_Configuration::save()`, which
 * reports failure by returning false rather than by throwing. Ignoring that result left the form
 * reporting success while the submitted values existed only in request memory, and left the host
 * disabling an extension whose reading-mode restoration had not been written.
 *
 * These execute the real methods against framework stand-ins, because a source-string assertion
 * cannot establish either contract.
 */
#[CoversClass(FreshVibesViewExtension::class)]
final class ExtensionEntrypointTest extends TestCase {

	private FreshVibesViewExtension $extension;
	private ConfigurationDouble $conf;

	#[\Override]
	protected function setUp(): void {
		$this->extension = new FreshVibesViewExtension();
		$this->conf = new ConfigurationDouble();
		FreshRSS_Context::$conf = $this->conf;
		Minz_Log::reset();
		Minz_Request::reset(self::validForm());
	}

	#[\Override]
	protected function tearDown(): void {
		FreshRSS_Context::$conf = null;
		Minz_Request::reset();
		Minz_Log::reset();
	}

	/** @return array<string,mixed> */
	private static function validForm(): array {
		return [
			'freshvibes_refresh_enabled' => '1',
			'freshvibes_refresh_interval' => '30',
			'freshvibes_date_format' => 'Y-m-d',
			'freshvibes_max_entries_cap' => '250',
			'freshvibes_view_mode' => 'categories',
			'freshvibes_entry_click_mode' => 'external',
			'freshvibes_date_mode' => 'relative',
			'freshvibes_new_feed_position' => 'top',
			'freshvibes_empty_feeds_display' => 'hide_completely',
			'freshvibes_dashboard_layout' => 'vertical',
		];
	}

	// ---------------------------------------------------------------- configuration form

	public function testASuccessfulSubmissionStoresTheSettings(): void {
		$this->extension->handleConfigureAction();

		self::assertSame(1, $this->conf->saveCalls);
		self::assertSame('categories', $this->conf->data[FreshVibesViewExtension::MODE_CONFIG_KEY]);
		self::assertSame('Y-m-d', $this->conf->data[FreshVibesViewExtension::DATE_FORMAT_CONFIG_KEY]);
		self::assertSame(30, $this->conf->data[FreshVibesViewExtension::REFRESH_INTERVAL_CONFIG_KEY]);
		self::assertSame('vertical', $this->conf->data[FreshVibesViewExtension::DASHBOARD_LAYOUT_CONFIG_KEY]);
		self::assertSame([], Minz_Log::messages('error'));
	}

	/**
	 * The reported defect: a false `save()` produced no failure signal at all, so the host's
	 * `configureAction()` reported success for values that were never written.
	 */
	public function testAFailedSaveIsReportedThroughTheHostErrorPath(): void {
		$this->conf->saveSucceeds = false;

		try {
			$this->extension->handleConfigureAction();
			self::fail('A failed configuration save must reach the host as a Minz_Exception.');
		} catch (Minz_Exception $e) {
			self::assertStringContainsString('could not be stored', $e->getMessage());
		}

		self::assertSame(1, $this->conf->saveCalls);
		self::assertNotSame([], Minz_Log::messages('error'));
	}

	public function testANonPostRequestWritesNothing(): void {
		Minz_Request::reset(self::validForm(), isPost: false);

		$this->extension->handleConfigureAction();

		self::assertSame(0, $this->conf->saveCalls);
		self::assertSame([], $this->conf->data);
	}

	/** Every enumerated setting is still checked against its allow-list before being stored. */
	public function testOutOfRangeAndUnknownValuesFallBackToTheDeclaredDefaults(): void {
		Minz_Request::reset([
			'freshvibes_refresh_interval' => '999999',
			'freshvibes_max_entries_cap' => '999999',
			'freshvibes_date_format' => str_repeat('Y', FreshVibesViewExtension::MAX_DATE_FORMAT_LENGTH + 1),
			'freshvibes_view_mode' => 'not-a-mode',
			'freshvibes_entry_click_mode' => 'not-a-mode',
			'freshvibes_dashboard_layout' => 'not-a-layout',
		]);

		$this->extension->handleConfigureAction();

		self::assertSame(
			FreshVibesViewExtension::MAX_REFRESH_INTERVAL,
			$this->conf->data[FreshVibesViewExtension::REFRESH_INTERVAL_CONFIG_KEY]
		);
		self::assertSame(
			FreshVibesViewExtension::MAX_MAX_ENTRIES_CAP,
			$this->conf->data[FreshVibesViewExtension::MAX_ENTRIES_CAP_CONFIG_KEY]
		);
		self::assertSame(
			FreshVibesViewExtension::DEFAULT_DATE_FORMAT,
			$this->conf->data[FreshVibesViewExtension::DATE_FORMAT_CONFIG_KEY]
		);
		self::assertSame('custom', $this->conf->data[FreshVibesViewExtension::MODE_CONFIG_KEY]);
		self::assertSame('modal', $this->conf->data[FreshVibesViewExtension::ENTRY_CLICK_MODE_CONFIG_KEY]);
		self::assertSame(
			FreshVibesViewExtension::DEFAULT_DASHBOARD_LAYOUT,
			$this->conf->data[FreshVibesViewExtension::DASHBOARD_LAYOUT_CONFIG_KEY]
		);
	}

	// ---------------------------------------------------------------- uninstall

	public function testUninstallRestoresTheReadingModeAndReportsSuccess(): void {
		$this->conf->data['view_mode'] = FreshVibesViewExtension::CONTROLLER_NAME_BASE;

		self::assertTrue($this->extension->uninstall());
		self::assertSame('normal', $this->conf->data['view_mode']);
		self::assertSame(1, $this->conf->saveCalls);
	}

	/**
	 * The host reads anything other than `true` as a refusal and leaves the extension enabled. That
	 * is the right outcome when the reading mode could not be restored: disabling it anyway would
	 * leave the user on a view mode with no controller behind it.
	 */
	public function testUninstallRefusesWhenTheReadingModeCannotBeRestored(): void {
		$this->conf->data['view_mode'] = FreshVibesViewExtension::CONTROLLER_NAME_BASE;
		$this->conf->saveSucceeds = false;

		$result = $this->extension->uninstall();

		self::assertIsString($result);
		self::assertNotSame('', $result);
		self::assertSame(1, $this->conf->saveCalls);
	}

	/** @return array<string,array{array<string,mixed>}> */
	public static function otherReadingModeProvider(): array {
		return [
			'another extension owns the view' => [['view_mode' => 'normal']],
			'no view mode set' => [[]],
		];
	}

	#[DataProvider('otherReadingModeProvider')]
	public function testUninstallLeavesAnotherReadingModeAlone(array $stored): void {
		$this->conf->data = $stored;

		self::assertTrue($this->extension->uninstall());
		self::assertSame(0, $this->conf->saveCalls);
		self::assertSame($stored, $this->conf->data);
	}
}
