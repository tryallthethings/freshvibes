<?php

declare(strict_types=1);

/**
 * Minimal stand-ins for the FreshRSS/Minz globals the extension entrypoint touches.
 *
 * `ExtensionEntrypointTest` executes the real `handleConfigureAction()` and `uninstall()`, so the
 * globals they reach have to exist. Each one reproduces only the behaviour the entrypoint depends
 * on, and the configuration double reproduces `Minz_Configuration`'s actual semantics: assigning
 * null removes a key, `hasParam()` is an `isset()`, and `save()` reports failure by returning
 * false rather than by throwing.
 *
 * Nothing here simulates FreshRSS. Anything needing real framework behaviour belongs in an
 * integration test against a deployed instance.
 */

if (!class_exists('Minz_Exception', false)) {
	class Minz_Exception extends Exception {
		public const ERROR = 0;
	}
}

if (!class_exists('Minz_Log', false)) {
	final class Minz_Log {
		/** @var list<array{string,string}> */
		public static array $records = [];

		public static function reset(): void {
			self::$records = [];
		}

		public static function error(string $message): void {
			self::$records[] = ['error', $message];
		}

		public static function warning(string $message): void {
			self::$records[] = ['warning', $message];
		}

		/** @return list<string> */
		public static function messages(string $level): array {
			$found = [];
			foreach (self::$records as [$recordLevel, $message]) {
				if ($recordLevel === $level) {
					$found[] = $message;
				}
			}
			return $found;
		}
	}
}

if (!class_exists('Minz_Request', false)) {
	final class Minz_Request {
		/** @var array<string,mixed> */
		public static array $params = [];
		public static bool $isPost = true;

		/** @param array<string,mixed> $params */
		public static function reset(array $params = [], bool $isPost = true): void {
			self::$params = $params;
			self::$isPost = $isPost;
		}

		public static function isPost(): bool {
			return self::$isPost;
		}

		public static function paramBoolean(string $key): bool {
			return !empty(self::$params[$key]);
		}

		public static function paramInt(string $key): int {
			return isset(self::$params[$key]) && is_numeric(self::$params[$key]) ? (int)self::$params[$key] : 0;
		}

		public static function paramStringNull(string $key, bool $plaintext = false): ?string {
			if (!isset(self::$params[$key]) || !is_string(self::$params[$key])) {
				return null;
			}
			$value = trim(self::$params[$key]);
			return $plaintext ? $value : htmlspecialchars($value, ENT_COMPAT, 'UTF-8');
		}

		public static function paramString(string $key, bool $plaintext = false): string {
			return self::paramStringNull($key, $plaintext) ?? '';
		}
	}
}

if (!class_exists('ConfigurationDouble', false)) {
	/** Reproduces the parts of `Minz_Configuration` the entrypoint uses. */
	final class ConfigurationDouble {
		/** @var array<string,mixed> */
		public array $data = [];
		public int $saveCalls = 0;
		public bool $saveSucceeds = true;

		/** @param array<string,mixed> $data */
		public function __construct(array $data = []) {
			$this->data = $data;
		}

		public function hasParam(string $key): bool {
			return isset($this->data[$key]);
		}

		public function param(string $key, mixed $default = null): mixed {
			return $this->data[$key] ?? $default;
		}

		public function attributeBool(string $key): ?bool {
			$value = $this->data[$key] ?? null;
			return is_bool($value) ? $value : null;
		}

		public function attributeArray(string $key): ?array {
			$value = $this->data[$key] ?? null;
			return is_array($value) ? $value : null;
		}

		public function attributeString(string $key): ?string {
			$value = $this->data[$key] ?? null;
			return is_string($value) ? $value : null;
		}

		public function attributeInt(string $key): ?int {
			$value = $this->data[$key] ?? null;
			return is_numeric($value) ? (int)$value : null;
		}

		public function _attribute(string $key, mixed $value = null): void {
			if (isset($this->data[$key]) && $value === null) {
				unset($this->data[$key]);
			} elseif ($value !== null) {
				$this->data[$key] = $value;
			}
		}

		public function __get(string $key): mixed {
			return $this->data[$key] ?? null;
		}

		public function save(): bool {
			$this->saveCalls++;
			return $this->saveSucceeds;
		}
	}
}

if (!function_exists('_t')) {
	/** Translation lookup. The suite asserts on structure, so the key's last segment is enough. */
	function _t(string $key, bool|float|int|string ...$args): string {
		$parts = explode('.', $key);
		return (string)end($parts);
	}
}

if (!class_exists('Minz_ActionController', false)) {
	abstract class Minz_ActionController {
		/** @var mixed */
		protected $view;

		public function firstAction(): void {
		}
	}
}

if (!class_exists('FeedDouble', false)) {
	/** The only part of `FreshRSS_Feed` the layout code reads. */
	final class FeedDouble {
		public function __construct(private readonly int $id) {
		}

		public function id(): int {
			return $this->id;
		}
	}
}

if (!class_exists('FeedDaoDouble', false)) {
	final class FeedDaoDouble {
		/** @var list<FeedDouble> */
		public static array $feeds = [];

		/** @param list<int> $ids */
		public static function subscribe(array $ids): void {
			self::$feeds = array_map(static fn(int $id): FeedDouble => new FeedDouble($id), $ids);
		}

		/** @return list<FeedDouble> */
		public function listFeeds(): array {
			return self::$feeds;
		}
	}
}

if (!class_exists('FreshRSS_Factory', false)) {
	final class FreshRSS_Factory {
		public static function createFeedDao(): FeedDaoDouble {
			return new FeedDaoDouble();
		}
	}
}

if (!class_exists('FreshRSS_Context', false)) {
	final class FreshRSS_Context {
		public static ?ConfigurationDouble $conf = null;

		public static function userConf(): ConfigurationDouble {
			if (self::$conf === null) {
				self::$conf = new ConfigurationDouble();
			}
			return self::$conf;
		}
	}
}
