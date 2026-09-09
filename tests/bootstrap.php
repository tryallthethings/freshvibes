<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * The classes under test are deliberately free of FreshRSS dependencies, so the suite runs without
 * a FreshRSS checkout. In production these files are loaded by the extension autoloader registered
 * in `extension.php`; here the same namespace-to-path mapping is reproduced.
 */

require_once __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
	$prefix = 'tryallthethings\\FreshVibes\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$relative = substr($class, strlen($prefix));
	$path = __DIR__ . '/../' . str_replace('\\', '/', $relative) . '.php';
	if (is_readable($path)) {
		require_once $path;
	}
});

// ConfigKeys reads the key constants from the extension entrypoint, which is a plain class with no
// FreshRSS dependencies at definition time. Loading it directly keeps the suite self-contained.
if (!class_exists('FreshVibesViewExtension', false)) {
	require_once __DIR__ . '/stubs/Minz_Extension.php';
	// The entrypoint's methods reach for framework globals, so those stand-ins are loaded too.
	// Defining a constant is not enough: `ExtensionEntrypointTest` executes the real methods.
	require_once __DIR__ . '/stubs/framework.php';
	require_once __DIR__ . '/../extension.php';
}
