<?php

declare(strict_types=1);

/**
 * Minimal stand-in for FreshRSS's `Minz_Extension`.
 *
 * `extension.php` is loaded by the suite only for its configuration-key constants, which are plain
 * strings with no framework behaviour behind them. This stub lets that file be included without a
 * FreshRSS checkout; it declares exactly the methods the entrypoint marks `#[\Override]`, so the
 * attribute stays enforced rather than being silenced.
 *
 * Nothing here simulates FreshRSS. Anything that needs real framework behaviour belongs in an
 * integration test against a deployed instance.
 */
abstract class Minz_Extension {

	public function init(): void {
	}

	/** @return bool */
	public function uninstall() {
		return true;
	}

	public function handleConfigureAction(): void {
	}
}
