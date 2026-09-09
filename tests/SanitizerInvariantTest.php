<?php

declare(strict_types=1);

namespace tryallthethings\FreshVibes\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use tryallthethings\FreshVibes\Models\Sanitizer;

/**
 * Output-shape tests for the sanitizer (FV-V01).
 *
 * The open question in the review was that `DOMDocument` is an HTML4 parser while the browser that
 * receives this output uses HTML5, and PHP documents that divergence as a reason not to rely on
 * `loadHTML()` for sanitisation. `Models\Sanitizer` now uses PHP 8.4's spec-compliant
 * `Dom\HTMLDocument` where it exists and keeps `DOMDocument` for older runtimes, so the CI matrix
 * exercises both parsers.
 *
 * That does not make a parser choice the safety argument, and these tests deliberately do not test
 * one. They assert the property that holds whichever parser ran: the output is rebuilt from an
 * allow-list and re-serialised with escaping, so it contains only allow-listed elements, only the
 * attributes those elements may keep, only allow-listed URL schemes, no comments, and no stray `<`.
 * A browser reparsing such a document cannot reach foreign content or a raw-text element, which is
 * what every known mutation-XSS construction needs.
 *
 * Scope limit unchanged: no browser executes here, so this is not a cross-browser differential test.
 */
#[CoversClass(Sanitizer::class)]
final class SanitizerInvariantTest extends TestCase {

	/** @var array<string,list<string>>|null */
	private static ?array $allowed = null;

	/** @return array<string,list<string>> */
	private static function allowedElements(): array {
		if (self::$allowed === null) {
			/** @var array<string,list<string>> $allowed */
			$allowed = (new ReflectionClass(Sanitizer::class))->getConstant('ALLOWED_ELEMENTS');
			self::$allowed = $allowed;
		}
		return self::$allowed;
	}

	/**
	 * Split a serialised tag's attribute section into name => value pairs.
	 *
	 * Quoted values are consumed whole, so markup that a publisher hid inside an attribute value is
	 * not mistaken for a second attribute.
	 *
	 * @return array<string,string>
	 */
	private static function parseAttributes(string $section): array {
		$pattern = '/(?:^|\s)([a-zA-Z0-9:_-]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*))?/';
		$matches = [];
		preg_match_all($pattern, $section, $matches, PREG_SET_ORDER);

		$attributes = [];
		foreach ($matches as $match) {
			$value = $match[2] ?? '';
			if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
				$value = substr($value, 1, -1);
			}
			$attributes[strtolower($match[1])] = $value;
		}
		return $attributes;
	}

	/**
	 * Assert every property the output must have, whatever the input was.
	 *
	 * `target` and `rel` are added by the sanitizer itself to surviving links and are therefore
	 * expected rather than allow-listed per element.
	 */
	private static function assertOutputIsInert(string $output, string $input): void {
		$allowed = self::allowedElements();
		$context = 'input: ' . $input . "\noutput: " . $output;

		$tagPattern = '/<\s*\/?\s*([a-zA-Z0-9:_-]+)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>/';
		$matches = [];
		preg_match_all($tagPattern, $output, $matches, PREG_SET_ORDER);

		foreach ($matches as $tag) {
			$name = strtolower($tag[1]);
			self::assertArrayHasKey($name, $allowed, 'Non-allow-listed element survived. ' . $context);

			foreach (self::parseAttributes($tag[2]) as $attribute => $value) {
				if ($attribute === 'target' || $attribute === 'rel') {
					continue;
				}
				self::assertContains(
					$attribute,
					$allowed[$name],
					'Non-allow-listed attribute survived on <' . $name . '>. ' . $context
				);

				// Only a URL-bearing attribute can navigate; the same text inside a `title` is
				// inert, so checking every value would reject correctly escaped output.
				if (in_array($attribute, ['href', 'cite', 'src'], true)) {
					self::assertDoesNotMatchRegularExpression(
						'/^\s*(javascript|vbscript|data)\s*:/i',
						html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
						'A rejected URL scheme survived. ' . $context
					);
				}
			}
		}

		// Anything left that looks like markup but did not match a well-formed tag would be reparsed
		// by the browser differently from how it was serialised here.
		$withoutTags = (string)preg_replace($tagPattern, '', $output);
		self::assertStringNotContainsString('<', $withoutTags, 'Stray markup delimiter. ' . $context);
		self::assertStringNotContainsString('<!--', $output, 'A comment survived. ' . $context);
	}

	/** @return array<string,array{string}> */
	public static function knownPayloadProvider(): array {
		$payloads = [
			'image with onerror' => '<img src=x onerror=alert(1)>',
			'script in svg' => '<svg><script>alert(1)</script></svg>',
			'svg style comment' => '<svg><style><!--</style><img src=x onerror=alert(1)>',
			'math mtext mglyph' => '<math><mtext><table><mglyph><style><!--</style><img src=x onerror=alert(1)>',
			'nested form mutation' => '<form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>',
			'noscript attribute break' => '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
			'template escape' => '<template><s>000</template><img src=x onerror=alert(1)>',
			'javascript href' => '<a href="javascript:alert(1)">x</a>',
			'tab in scheme' => "<a href=\"jav&#x09;ascript:alert(1)\">x</a>",
			'entity encoded scheme' => '<a href="&#106;avascript:alert(1)">x</a>',
			'data url' => '<a href="data:text/html,<script>alert(1)</script>">x</a>',
			'style expression' => '<p style="background:url(javascript:alert(1))">x</p>',
			'bogus comment' => '<!--><img src=x onerror=alert(1)>-->',
			'cdata' => '<![CDATA[<img src=x onerror=alert(1)>]]>',
			'xmp raw text' => '<xmp><img src=x onerror=alert(1)></xmp>',
			'iframe srcdoc' => '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>',
			'style expression block' => '<style>*{x:expression(alert(1))}</style>',
			'listing raw text' => '<listing><script>alert(1)</script></listing>',
			'plaintext to eof' => '<plaintext><img src=x onerror=alert(1)>',
			'textarea raw text' => '<textarea><img src=x onerror=alert(1)></textarea>',
			'title raw text' => '<title><img src=x onerror=alert(1)></title>',
			'select noembed' => '<select><noembed></select><img src=x onerror=alert(1)>',
			'table foreignobject' => '<table><caption><svg><foreignobject><style></table><img src=x onerror=alert(1)>',
			'attribute break out' => '<a href="http://x" title="&quot;><img src=x onerror=alert(1)>">t</a>',
			'self closing svg onload' => '<div><svg/onload=alert(1)>',
			'isindex action' => '<isindex action="javascript:alert(1)">',
			'body onload' => '<body onload=alert(1)>hi</body>',
			'leading whitespace scheme' => '<a href="  javascript:alert(1)">x</a>',
			'newline in scheme' => "<a href=\"java\nscript:alert(1)\">x</a>",
			'vbscript' => '<a href="vbscript:msgbox(1)">x</a>',
			'downlevel conditional' => '<span><![if]><img src=x onerror=alert(1)><![endif]></span>',
			'nul in scheme' => "<a href=\"javascript\u{0000}:alert(1)\">x</a>",
			'ie conditional comment' => '<p>x</p><!--[if IE]><img src=x onerror=alert(1)><![endif]-->',
			'svg namespaced anchor' => '<svg><a xlink:href="javascript:alert(1)">x</a></svg>',
			'unclosed everything' => '<div><span><a href="http://x"><b>text',
		];

		$cases = [];
		foreach ($payloads as $name => $payload) {
			$cases[$name] = [$payload];
		}
		return $cases;
	}

	#[DataProvider('knownPayloadProvider')]
	public function testKnownPayloadsProduceInertOutput(string $payload): void {
		self::assertOutputIsInert(Sanitizer::sanitizeHtml($payload, 100), $payload);
	}

	/**
	 * Fragments chosen because they change the parser's insertion mode: foreign content, raw-text
	 * elements, table scopes, and the constructs each parser reports differently.
	 *
	 * @return list<string>
	 */
	private static function fragments(): array {
		return [
			'<script>', '</script>', '<style>', '</style>', '<svg>', '</svg>', '<math>', '<mtext>',
			'<mglyph>', '<form>', '</form>', '<table>', '<caption>', '<td>', '<tr>', '<noscript>',
			'<template>', '<noembed>', '<xmp>', '<plaintext>', '<listing>', '<textarea>', '<title>',
			'<iframe>', '<img src=x onerror=alert(1)>', '<a href=javascript:alert(1)>', '</a>',
			'<p title="x">', '</p>', '<!--', '-->', '<![CDATA[', ']]>', '&lt;', '&gt;', '&quot;',
			'&#39;', '&amp;', '<b>', '</b>', 'text', '<div>', '</div>', '<select>', '<option>',
			'<i/>', '<br>', '</br>', '<base href=x>', '<!DOCTYPE html>', '<?xml version="1.0"?>',
			'<a href="&#106;avascript:alert(1)">', '<span onclick=alert(1)>', '</span>',
			'<foreignobject>', '<desc>', '<annotation-xml encoding="text/html">', '<ul>', '<li>',
			'<font>', '<center>', '<marquee>', '</body>', '</html>', '<body onload=alert(1)>',
		];
	}

	/**
	 * A deterministic sweep over combinations of those fragments.
	 *
	 * The seed is fixed so a failure is reproducible; the point is coverage of parser state
	 * transitions that a hand-written list does not reach.
	 */
	public function testCombinedFragmentsProduceInertOutput(): void {
		$fragments = self::fragments();
		$count = count($fragments);
		mt_srand(20260909);

		for ($case = 0; $case < 3000; $case++) {
			$input = '';
			$parts = mt_rand(1, 8);
			for ($part = 0; $part < $parts; $part++) {
				$input .= $fragments[mt_rand(0, $count - 1)];
			}
			self::assertOutputIsInert(Sanitizer::sanitizeHtml($input, 100), $input);
		}
	}

	// ---------------------------------------------------------------- fragment root

	/**
	 * The fragment is parsed inside a wrapper element. A raw-text element such as `<plaintext>`
	 * consumes everything to the end of the input, so a wrapper written *after* the fragment was
	 * swallowed and re-emitted as visible text in the excerpt.
	 *
	 * @return array<string,array{string}>
	 */
	public static function rawTextWrapperProvider(): array {
		return [
			'plaintext' => ['<plaintext>abc'],
			'xmp' => ['<xmp>abc'],
			'title' => ['<title>abc'],
			'noembed' => ['<noembed>abc'],
			'listing' => ['<listing>abc'],
			'textarea' => ['<textarea>abc'],
		];
	}

	#[DataProvider('rawTextWrapperProvider')]
	public function testTheParserWrapperNeverLeaksIntoTheOutput(string $payload): void {
		$output = Sanitizer::sanitizeHtml($payload, 100);
		self::assertStringNotContainsString('&lt;/div&gt;', $output);
		self::assertStringNotContainsString('&lt;/body&gt;', $output);
		self::assertStringNotContainsString('</div>', $output);
		self::assertStringNotContainsString('</body>', $output);
	}

	// ---------------------------------------------------------------- input bound

	public function testOverlongContentIsCutBeforeParsing(): void {
		$limit = (int)(new ReflectionClass(Sanitizer::class))->getConstant('MAX_INPUT_BYTES');
		self::assertGreaterThan(0, $limit);

		$huge = '<p>' . str_repeat('word ', $limit) . '</p>';
		self::assertGreaterThan($limit, strlen($huge));

		$output = Sanitizer::sanitizeHtml($huge, 5);
		self::assertLessThan($limit, strlen($output));
		self::assertOutputIsInert($output, '<p>word...</p>');
	}

	public function testCuttingOverlongContentKeepsValidUtf8(): void {
		$limit = (int)(new ReflectionClass(Sanitizer::class))->getConstant('MAX_INPUT_BYTES');
		// Four-byte characters, so a naive byte cut would land inside a sequence.
		$huge = str_repeat('😀', (int)ceil($limit / 2));

		self::assertTrue(mb_check_encoding(Sanitizer::sanitizeHtml($huge), 'UTF-8'));
		self::assertTrue(mb_check_encoding(Sanitizer::toText($huge), 'UTF-8'));
	}

	public function testASingleUnbrokenWordIsStillBounded(): void {
		$limit = (int)(new ReflectionClass(Sanitizer::class))->getConstant('MAX_INPUT_BYTES');
		$word = str_repeat('a', $limit * 2);

		self::assertLessThanOrEqual($limit, strlen(Sanitizer::toText($word)));
		self::assertLessThanOrEqual($limit, strlen(Sanitizer::sanitizeHtml($word)));
	}

	// ---------------------------------------------------------------- ordinary content

	/** @return array<string,array{string,string}> */
	public static function ordinaryContentProvider(): array {
		return [
			'paragraph' => ['<p>hello</p>', '<p>hello</p>'],
			'inline markup' => ['<b>bold</b> &amp; <i>it</i>', '<b>bold</b> &amp; <i>it</i>'],
			'plain text' => ['plain text', 'plain text'],
			'non ascii' => ['<p>Ünïcödé — ✓ 😀</p>', '<p>Ünïcödé — ✓ 😀</p>'],
			'safe link' => [
				'<a href="https://example.com">link</a>',
				'<a href="https://example.com" target="_blank" rel="noopener noreferrer nofollow">link</a>',
			],
		];
	}

	#[DataProvider('ordinaryContentProvider')]
	public function testOrdinaryContentIsPreservedIdenticallyOnEitherParser(string $input, string $expected): void {
		self::assertSame($expected, Sanitizer::sanitizeHtml($input, 100));
	}
}
