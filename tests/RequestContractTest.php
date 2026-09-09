<?php

declare(strict_types=1);

namespace tryallthethings\FreshVibes\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression fences for two controller defects that no helper-level test can reach (FV-001, FV-002).
 *
 * Both were invisible to the existing suite for the same reason: the helpers are handed values that
 * have already crossed the boundary where the defect lives. `validateColumns()` receives a decoded
 * array, so it cannot see that the request parser mangled the JSON before `json_decode()` ran, and
 * `ConfigKeys` cannot see a caller overwriting its own resolver variable.
 *
 * The suite is deliberately free of FreshRSS dependencies, so the controller cannot be executed
 * here. These tests therefore do two things: they reproduce the framework behaviour that caused
 * FV-001 with plain PHP, and they assert the controller source still carries the corrections. A
 * source assertion is a weaker test than an executed one; it exists because the alternative is no
 * test at all for a defect that made every ordinary layout save fail.
 */
final class RequestContractTest extends TestCase {

	private static function controllerSource(): string {
		$source = file_get_contents(__DIR__ . '/../Controllers/freshvibesController.php');
		self::assertIsString($source);
		return $source;
	}

	// ---------------------------------------------------------------- FV-001

	/**
	 * `Minz_Request::paramString()` returns `htmlspecialchars($value, ENT_COMPAT, 'UTF-8')` unless
	 * the caller asks for plaintext. Applied to a JSON body that turns every `"` into `&quot;`.
	 */
	public function testHtmlEscapingBreaksJsonDecoding(): void {
		$json = '{"col1":["1","2"],"col2":[]}';
		$escaped = htmlspecialchars($json, ENT_COMPAT, 'UTF-8');

		self::assertStringContainsString('&quot;', $escaped);
		self::assertNull(json_decode($escaped, true, 8));
		self::assertSame(JSON_ERROR_SYNTAX, json_last_error());

		self::assertSame(
			['col1' => ['1', '2'], 'col2' => []],
			json_decode($json, true, 8)
		);
		self::assertSame(JSON_ERROR_NONE, json_last_error());
	}

	public function testTheLayoutPayloadIsReadAsPlaintext(): void {
		self::assertStringContainsString(
			"Minz_Request::paramString('layout', true)",
			self::controllerSource(),
			'The layout payload is JSON and must be read unescaped, or json_decode() always fails.'
		);
	}

	/**
	 * Display strings are validated as typed: reading them escaped made the length limits count
	 * entities instead of characters and hid control characters behind their entity form.
	 */
	public function testDisplayStringsAreReadAsPlaintext(): void {
		$source = self::controllerSource();
		self::assertStringContainsString("Minz_Request::paramString('value', true)", $source);
		self::assertStringContainsString("Minz_Request::paramString('icon', true)", $source);
	}

	/**
	 * A tab name is stored escaped and decoded again by `getLayoutAction()`. That is also how every
	 * name written by an earlier release is stored, so both encodings have to survive the reader
	 * unchanged — otherwise renaming a tab silently rewrites names containing `&` or `<`.
	 *
	 * @return array<string,array{string}>
	 */
	public static function tabNameProvider(): array {
		return [
			'plain' => ['My Tab'],
			'ampersand' => ['Tech & Science'],
			'angle brackets' => ['A <b> tag'],
			'quotes' => ['quote " and \'apos\''],
			'entity typed literally' => ['&amp; literal'],
			'emoji' => ['📊 Home'],
			'accents' => ['Ünïcödé'],
		];
	}

	#[DataProvider('tabNameProvider')]
	public function testTabNamesSurviveTheStoreAndReadRoundTrip(string $typed): void {
		// What updateTabAction stores, and what getLayoutAction hands back.
		$stored = htmlspecialchars($typed, ENT_QUOTES, 'UTF-8');
		self::assertSame($typed, html_entity_decode($stored, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

		// The same reader applied to a name written by an earlier release, which escaped through
		// Minz_Request::paramString() with ENT_COMPAT.
		$legacy = htmlspecialchars($typed, ENT_COMPAT, 'UTF-8');
		self::assertSame($typed, html_entity_decode($legacy, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}

	public function testTheRenamedTabIsStoredEscapedForThatReader(): void {
		self::assertStringContainsString(
			"htmlspecialchars(\$newName, ENT_QUOTES, 'UTF-8')",
			self::controllerSource(),
			'getLayoutAction() decodes tab names, so the writer must escape them.'
		);
	}

	// ---------------------------------------------------------------- FV-002

	/**
	 * The reset loop used to assign its key list back into `$keys`, replacing the `ConfigKeys`
	 * resolver with a plain array. The second feed then called `limit()` on an array, which raises
	 * an `Error`; `catch (Exception)` does not catch that, so no feed settings were ever removed.
	 */
	public function testResetUsesTheSharedKeyListAndKeepsItsResolver(): void {
		$source = self::controllerSource();
		$start = strpos($source, 'public function resetAllFeedSettingsAction');
		self::assertIsInt($start);
		$end = strpos($source, 'public function resetAllTabSettingsAction', $start);
		self::assertIsInt($end);
		$body = substr($source, $start, $end - $start);

		self::assertStringContainsString('$keys->allFeedKeys(', $body);
		self::assertDoesNotMatchRegularExpression(
			'/\$keys\s*=\s*\[/',
			$body,
			'$keys holds the ConfigKeys resolver and must not be overwritten with a key list. '
			. 'Doing so made the second feed call limit() on an array, which raises an Error; '
			. 'PHP does not treat an Error as an Exception, so broadening the catch would not help.'
		);
	}

	// ---------------------------------------------------------------- FV-004

	/**
	 * `Minz_Configuration::_attribute($key, null)` removes a key instead of storing a present-null,
	 * and `hasParam()` is an `isset()`. A null "default" therefore never becomes present, so the
	 * seeding pass marked the configuration dirty and saved it on every single dashboard read.
	 */
	public function testNoLayoutKeyIsSeededWithANullDefault(): void {
		$source = self::controllerSource();
		$start = strpos($source, 'private function initializeDefaultSettings');
		self::assertIsInt($start);
		$end = strpos($source, 'private function getLayout(', $start);
		self::assertIsInt($end);
		$body = substr($source, $start, $end - $start);

		self::assertDoesNotMatchRegularExpression(
			'/CONFIG_KEY\s*=>\s*null/',
			$body,
			'A null default is never stored, so it is rewritten on every read.'
		);
	}

	public function testConfigurationReadsAreGuardedByHasParam(): void {
		$source = self::controllerSource();
		$start = strpos($source, 'private function initializeDefaultSettings');
		self::assertIsInt($start);
		$end = strpos($source, 'private function getLayout(', $start);
		self::assertIsInt($end);
		$body = substr($source, $start, $end - $start);

		// `param()` on an absent key logs a warning, so it is only reached once existence is known.
		self::assertStringContainsString('if (!$userConf->hasParam($key)) {', $body);
	}

	// ---------------------------------------------------------------- FV-005

	/**
	 * Every `save()` on a state-changing path is answered, not discarded.
	 *
	 * The extension entrypoint is included: it was missed the first time because this fence only
	 * read the controller, while `handleConfigureAction()` and `uninstall()` were still throwing
	 * the boolean away. `ExtensionEntrypointTest` covers their behaviour; this keeps a new
	 * unchecked call site from appearing in either file.
	 *
	 * @return array<string,array{string}>
	 */
	public static function persistenceSourceProvider(): array {
		return [
			'controller' => [__DIR__ . '/../Controllers/freshvibesController.php'],
			'entrypoint' => [__DIR__ . '/../extension.php'],
		];
	}

	#[DataProvider('persistenceSourceProvider')]
	public function testPersistenceResultsAreChecked(string $path): void {
		$source = file_get_contents($path);
		self::assertIsString($source);
		self::assertSame(
			0,
			preg_match_all('/^\t+\$userConf->save\(\);$/m', $source),
			'An unchecked save() lets a failed write be reported as success.'
		);
	}

	public function testTheControllerHasADedicatedPersistenceFailurePath(): void {
		self::assertStringContainsString('private function failWithPersistenceError(', self::controllerSource());
	}
}
