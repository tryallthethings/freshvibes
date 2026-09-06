<?php

declare(strict_types=1);

namespace tryallthethings\FreshVibes\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tryallthethings\FreshVibes\Models\Sanitizer;

/**
 * Security regression tests for the feed-content sanitizer.
 *
 * These cover FV-SEC-01 (entity-escaped markup reactivated into an `innerHTML` sink) and FV-SEC-02
 * (unsafe URL schemes reaching link targets). The assertions are deliberately about what must *not*
 * survive, so a future refactor that loosens the allow-list fails here rather than in the browser.
 *
 * Scope limit: this exercises PHP's DOM parser, which is not the parser any browser uses. It cannot
 * replace a cross-browser differential test of the rendered output.
 */
#[CoversClass(Sanitizer::class)]
final class SanitizerTest extends TestCase {

	// ---------------------------------------------------------------- FV-SEC-01

	/**
	 * The reported and reproduced vulnerability: FreshRSS stores feed text with the XML-sensitive
	 * characters entity-encoded on purpose. Decoding them turns text back into live markup.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function entityEscapedMarkupProvider(): array {
		return [
			'svg with event handler' => ['&lt;svg onload=proof&gt;&lt;/svg&gt;', '<svg onload=proof></svg>'],
			'form posting off-site' => [
				'&lt;form action=https://attacker.invalid&gt;&lt;/form&gt;',
				'<form action=https://attacker.invalid></form>',
			],
			'script element' => ['&lt;script&gt;proof()&lt;/script&gt;', '<script>proof()</script>'],
			'image with onerror' => ['&lt;img src=x onerror=proof&gt;', '<img src=x onerror=proof>'],
			'numeric entities' => ['&#60;svg onload=proof&#62;', '<svg onload=proof>'],
			'hex entities' => ['&#x3C;svg onload=proof&#x3E;', '<svg onload=proof>'],
		];
	}

	/**
	 * @param string $stored Content as FreshRSS stores it.
	 * @param string $expectedText The same content as it must appear to the reader: inert text.
	 */
	#[DataProvider('entityEscapedMarkupProvider')]
	public function testEntityEscapedMarkupIsNotReactivated(string $stored, string $expectedText): void {
		$result = Sanitizer::sanitizeHtml($stored, 100);

		// Two halves, and both matter. Stripping the content entirely would satisfy the first
		// assertion alone, so the second pins the correct behaviour: the markup a feed escaped
		// stays visible to the reader as text rather than being deleted or reactivated.
		self::assertSame([], self::elementNames($result), 'no element may be created');
		self::assertSame([], self::attributeNames($result), 'no attribute may be created');
		self::assertSame($expectedText, self::renderedText($result), 'escaped markup must survive as text');
	}

	/** The text a browser would display for a fragment. */
	private static function renderedText(string $html): string {
		return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	/**
	 * Element names present in a fragment, lower-cased.
	 *
	 * @return list<string>
	 */
	private static function elementNames(string $html): array {
		$names = [];
		foreach (self::elements($html) as $element) {
			$names[] = strtolower($element->nodeName);
		}
		sort($names);
		return $names;
	}

	/**
	 * Attribute names present anywhere in a fragment, lower-cased.
	 *
	 * @return list<string>
	 */
	private static function attributeNames(string $html): array {
		$names = [];
		foreach (self::elements($html) as $element) {
			foreach ($element->attributes ?? [] as $attribute) {
				$names[] = strtolower($attribute->nodeName);
			}
		}
		sort($names);
		return array_values(array_unique($names));
	}

	/** @return list<\DOMElement> */
	private static function elements(string $html): array {
		if (trim($html) === '') {
			return [];
		}
		$doc = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$doc->loadHTML(
			'<?xml encoding="utf-8" ?><div id="root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$found = [];
		foreach ((new \DOMXPath($doc))->query('//*[@id="root"]//*') as $element) {
			if ($element instanceof \DOMElement) {
				$found[] = $element;
			}
		}
		return $found;
	}

	public function testEntityEscapedTextRemainsEscapedText(): void {
		$result = Sanitizer::sanitizeHtml('&lt;svg onload=proof&gt;&lt;/svg&gt;', 100);

		// Still present, still inert.
		self::assertStringContainsString('&lt;svg', $result);
		self::assertStringNotContainsString('<svg', $result);
	}

	/**
	 * Elements that must never survive, together with their content.
	 *
	 * @return array<string,array{string}>
	 */
	public static function dangerousElementProvider(): array {
		return [
			'script' => ['<p>keep</p><script>proof()</script>'],
			'style' => ['<p>keep</p><style>body{display:none}</style>'],
			'iframe' => ['<p>keep</p><iframe src="https://attacker.invalid"></iframe>'],
			'object' => ['<p>keep</p><object data="x"></object>'],
			'embed' => ['<p>keep</p><embed src="x">'],
			'form' => ['<p>keep</p><form action="https://attacker.invalid"><input name="a"></form>'],
			'button' => ['<p>keep</p><button formaction="https://attacker.invalid">go</button>'],
			'svg' => ['<p>keep</p><svg><use href="#x"/></svg>'],
			'math' => ['<p>keep</p><math><mtext>x</mtext></math>'],
			'base' => ['<p>keep</p><base href="https://attacker.invalid/">'],
			'meta refresh' => ['<p>keep</p><meta http-equiv="refresh" content="0;url=https://attacker.invalid">'],
			'link stylesheet' => ['<p>keep</p><link rel="stylesheet" href="https://attacker.invalid/x.css">'],
			'textarea' => ['<p>keep</p><textarea>x</textarea>'],
			'noscript' => ['<p>keep</p><noscript>x</noscript>'],
			'video' => ['<p>keep</p><video src="https://attacker.invalid/v.mp4"></video>'],
		];
	}

	#[DataProvider('dangerousElementProvider')]
	public function testDangerousElementsAreRemoved(string $input): void {
		$result = Sanitizer::sanitizeHtml($input, 100);

		self::assertStringContainsString('keep', $result, 'surrounding content should survive');
		foreach (['script', 'style', 'iframe', 'object', 'embed', 'form', 'button', 'svg', 'math',
			'base', 'meta', 'link', 'textarea', 'noscript', 'video'] as $tag) {
			self::assertStringNotContainsString('<' . $tag, strtolower($result));
		}
		self::assertStringNotContainsString('attacker.invalid', $result);
	}

	/**
	 * Attributes that must never survive on an allow-listed element.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function dangerousAttributeProvider(): array {
		return [
			'onclick' => ['<p onclick="proof()">t</p>', 'onclick'],
			'onmouseover unquoted' => ['<p onmouseover=proof()>t</p>', 'onmouseover'],
			'onerror mixed case' => ['<p OnErRoR="proof()">t</p>', 'onerror'],
			'style' => ['<p style="background:url(https://x.invalid)">t</p>', 'style'],
			'id (DOM clobbering)' => ['<div id="csrfToken">t</div>', 'id'],
			'class' => ['<p class="fv-modal-title">t</p>', 'class'],
			'srcset' => ['<p srcset="https://x.invalid/a.png">t</p>', 'srcset'],
			'formaction' => ['<p formaction="https://attacker.invalid">t</p>', 'formaction'],
			'data attribute' => ['<p data-x="1">t</p>', 'data-x'],
		];
	}

	#[DataProvider('dangerousAttributeProvider')]
	public function testDangerousAttributesAreStripped(string $input, string $attribute): void {
		$result = Sanitizer::sanitizeHtml($input, 100);

		self::assertStringNotContainsStringIgnoringCase($attribute . '=', $result);
		self::assertStringContainsString('t', $result);
	}

	public function testImagesAreRemovedSoExcerptsMakeNoThirdPartyRequests(): void {
		$result = Sanitizer::sanitizeHtml('<p>before<img src="https://tracker.invalid/p.gif">after</p>', 100);

		self::assertStringNotContainsString('<img', $result);
		self::assertStringNotContainsString('tracker.invalid', $result);
	}

	public function testCommentsAreRemoved(): void {
		self::assertStringNotContainsString('secret', Sanitizer::sanitizeHtml('<p>a<!-- secret -->b</p>', 100));
	}

	public function testAllowedFormattingSurvives(): void {
		$result = Sanitizer::sanitizeHtml('<p>A <strong>bold</strong> and <em>italic</em> line.</p><ul><li>one</li></ul>', 100);

		self::assertStringContainsString('<strong>bold</strong>', $result);
		self::assertStringContainsString('<em>italic</em>', $result);
		self::assertStringContainsString('<li>one</li>', $result);
	}

	public function testUnknownElementsAreUnwrappedButTheirTextKept(): void {
		$result = Sanitizer::sanitizeHtml('<header><p>deep <b>text</b></p></header>', 100);

		self::assertStringNotContainsString('<header', $result);
		self::assertStringContainsString('deep', $result);
		self::assertStringContainsString('<b>text</b>', $result);
	}

	/**
	 * Malformed markup must not let a tag escape the sanitizer by confusing the parser.
	 *
	 * @return array<string,array{string}>
	 */
	public static function malformedMarkupProvider(): array {
		return [
			'unclosed tags' => ['<p>unclosed <b>bold <i>italic</p>'],
			'stray close tags' => ['</p></div>text</span>'],
			'nested unterminated script' => ['<p>a<script>var x = "</p>";proof()</script>'],
			'attribute without quotes' => ['<p title=a b=c>t</p>'],
			'null byte' => ["<p>a\0<script>proof()</script></p>"],
			'overlong nesting' => [str_repeat('<div>', 40) . 'x' . str_repeat('</div>', 40)],
			'broken comment' => ['<p>a<!-- unterminated'],
			'bare lt' => ['<p>1 < 2 and 3 > 2</p>'],
		];
	}

	#[DataProvider('malformedMarkupProvider')]
	public function testMalformedMarkupNeverYieldsExecutableOutput(string $input): void {
		$result = Sanitizer::sanitizeHtml($input, 100);

		self::assertStringNotContainsStringIgnoringCase('<script', $result);
		self::assertStringNotContainsStringIgnoringCase('proof()', $result);
		self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $result);
	}

	public function testTruncationHappensOnTheDomAndNeverSplitsATag(): void {
		$long = '<p>' . implode(' ', array_map(static fn(int $i): string => "word$i", range(1, 40))) . '</p>'
			. '<p><a href="https://example.test/x">later</a></p>';

		$result = Sanitizer::sanitizeHtml($long, 5);

		self::assertStringContainsString('word5', $result);
		self::assertStringNotContainsString('word6', $result);
		// The second paragraph is dropped whole; no dangling markup is left behind.
		self::assertStringNotContainsString('later', $result);
		self::assertSame(substr_count($result, '<p>'), substr_count($result, '</p>'));
	}

	public function testEmptyInputYieldsEmptyOutput(): void {
		self::assertSame('', Sanitizer::sanitizeHtml('', 100));
		self::assertSame('', Sanitizer::sanitizeHtml('   ', 100));
	}

	// ---------------------------------------------------------------- FV-SEC-02 / FV-CORR-01

	/**
	 * @return array<string,array{string}>
	 */
	public static function unsafeUrlProvider(): array {
		return [
			'javascript' => ['javascript:alert(1)'],
			'javascript mixed case' => ['JaVaScRiPt:alert(1)'],
			'javascript with tab' => ["java\tscript:alert(1)"],
			'javascript with newline' => ["java\nscript:alert(1)"],
			'javascript entity-encoded' => ['&#106;avascript:alert(1)'],
			'data html' => ['data:text/html,<script>alert(1)</script>'],
			'vbscript' => ['vbscript:msgbox(1)'],
			'file' => ['file:///etc/passwd'],
			'protocol relative' => ['//evil.test/x'],
			'relative path' => ['/relative'],
			'empty' => [''],
			'whitespace only' => ['   '],
		];
	}

	#[DataProvider('unsafeUrlProvider')]
	public function testUnsafeUrlsAreRejected(string $url): void {
		self::assertSame('', Sanitizer::safeUrl($url));
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public static function safeUrlProvider(): array {
		return [
			'https' => ['https://example.test/a', 'https://example.test/a'],
			'http' => ['http://example.test/a', 'http://example.test/a'],
			'mailto' => ['mailto:a@b.test', 'mailto:a@b.test'],
			// FreshRSS documents Entry::link()/Feed::website() as HTML-encoded, so entities must be
			// decoded once as URL data or "b" becomes the literal key "amp;b" (FV-CORR-01).
			'entity encoded ampersand' => [
				'https://example.test/a?x=1&amp;y=2',
				'https://example.test/a?x=1&y=2',
			],
			'surrounding whitespace' => ['  https://example.test/a  ', 'https://example.test/a'],
		];
	}

	#[DataProvider('safeUrlProvider')]
	public function testSafeUrlsArePreservedAndCanonicalised(string $input, string $expected): void {
		self::assertSame($expected, Sanitizer::safeUrl($input));
	}

	public function testUnsafeHrefIsDroppedButLinkTextSurvives(): void {
		$result = Sanitizer::sanitizeHtml('<a href="javascript:proof()">click</a>', 100);

		self::assertStringNotContainsString('javascript', $result);
		self::assertStringNotContainsString('href', $result);
		self::assertStringContainsString('click', $result);
	}

	public function testSafeLinksGetNoOpenerAndNoReferrer(): void {
		$result = Sanitizer::sanitizeHtml('<a href="https://example.test/x">ok</a>', 100);

		self::assertStringContainsString('href="https://example.test/x"', $result);
		self::assertStringContainsString('rel="noopener noreferrer nofollow"', $result);
		self::assertStringContainsString('target="_blank"', $result);
	}

	// ---------------------------------------------------------------- plain-text pipeline

	public function testToTextStripsTagsBeforeDecodingSoEscapedTextStaysText(): void {
		// Decoding first would turn this into live markup; the order is the whole fix.
		self::assertSame('<svg onload=proof>', Sanitizer::toText('&lt;svg onload=proof&gt;'));
	}

	public function testToTextDropsScriptAndStyleContent(): void {
		self::assertSame('hi', Sanitizer::toText('<p>hi</p><script>proof()</script><style>a{}</style>'));
	}

	public function testToTextSeparatesBlockBoundaries(): void {
		self::assertSame('one two', Sanitizer::toText('<p>one</p><p>two</p>'));
	}

	public function testTruncateWordsAppendsEllipsisOnlyWhenCutting(): void {
		self::assertSame('a b c', Sanitizer::truncateWords('a b c', 5));
		self::assertSame('a b…', Sanitizer::truncateWords('a b c d', 2));
	}

	public function testTruncateSentencesAppendsEllipsisOnlyWhenCutting(): void {
		self::assertSame('One. Two.', Sanitizer::truncateSentences('One. Two.', 3));
		self::assertSame('One. Two.…', Sanitizer::truncateSentences('One. Two. Three. Four.', 2));
	}

	// ---------------------------------------------------------------- FV-MAINT-03

	/**
	 * @return array<string,array{string,bool}>
	 */
	public static function hexColorProvider(): array {
		return [
			'lowercase' => ['#a1b2c3', true],
			'uppercase' => ['#A1B2C3', true],
			'missing hash' => ['a1b2c3', false],
			'three digit' => ['#abc', false],
			'too long' => ['#a1b2c3d', false],
			'non hex' => ['#zzzzzz', false],
			// Reached hexdec() before validation, which has emitted a deprecation since PHP 7.4.
			'partially non hex' => ['#re0000', false],
			'empty' => ['', false],
			'css function' => ['rgb(1,2,3)', false],
		];
	}

	#[DataProvider('hexColorProvider')]
	public function testIsHexColor(string $value, bool $expected): void {
		self::assertSame($expected, Sanitizer::isHexColor($value));
	}
}
