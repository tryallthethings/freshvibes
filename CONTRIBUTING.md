# Contributing to FreshVibes

Contributions are welcome. Please open a pull request, and join the
[Crowdin project](https://crowdin.com/project/freshvibes) if you would like to help translate.

## Development setup

```sh
composer install
```

PHPStan resolves FreshRSS classes from a checkout in a sibling directory. The unit tests do not
need one.

```sh
git clone --depth 1 --branch 1.29.1 https://github.com/FreshRSS/FreshRSS.git ../FreshRSS
```

## Running the checks

```sh
composer run test
```

That runs, in order: `php-lint`, `phtml-lint`, `phpcs`, `phpstan`, `phpunit`, `jstest` and
`verify-vendored`. Each is also available on its own, along with `phpcbf` for style fixes.

## What the tests cover

`tests/` covers the boundaries that are easy to break by accident:

- **Sanitizer.** Encoded and direct dangerous markup, malformed HTML, URL schemes, and an
  output-shape sweep over generated fragment combinations.
- **Layout schema.** Permutations, payload limits, structural ceilings and feed placement.
- **Configuration keys.** The literal stored key strings that existing installations depend on.
- **Entrypoint and controller.** Executed against framework doubles that reproduce
  `Minz_Configuration`'s semantics, including its boolean `save()` failure contract.
- **Client plumbing.** `tests/js/` runs the shipped reorder and refresh callbacks on Node's
  built-in test runner, with no `package.json` and no npm dependencies.

These are PHP and Node tests. They cannot substitute for checking sanitizer output in a real
browser, since PHP's DOM parser and a browser's HTML parser are not the same.

## Supported PHP versions

The declared minimum is PHP 8.1, matching FreshRSS's own. CI runs 8.1 through 8.5 because the
sanitizer takes different paths on either side of 8.4: `Dom\HTMLDocument` where it exists, and
`DOMDocument` otherwise. Both need to stay covered.

## Vendored assets

`static/*.VERSION` records the upstream version and SHA-256 of each vendored asset. `composer run
verify-vendored` checks the files against it, and CI runs the same check, because Dependabot cannot
track a standalone committed bundle.
