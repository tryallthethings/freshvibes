<?php

declare(strict_types=1);

namespace tryallthethings\FreshVibes\Models;

use DOMDocument;
use DOMElement;

/**
 * Output-safety helpers for untrusted feed content.
 *
 * FreshRSS stores entry content already sanitised by SimplePie, but deliberately keeps the five
 * XML-sensitive characters entity-encoded (see FreshRSS `html_only_entity_decode()`). Decoding
 * those entities turns text the feed escaped back into live markup, so this class never performs a
 * blanket `html_entity_decode()` on markup: HTML stays HTML and is filtered against an explicit
 * allow-list, while plain text is only decoded once, after tags have been removed.
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html
 */
final class Sanitizer {

	/**
	 * PHP 8.4's HTML5 document class, named as a string.
	 *
	 * It is referenced dynamically because this file also has to parse on PHP 8.1, where the class
	 * does not exist, and because `DOMElement` and `Dom\Element` are unrelated classes with the
	 * same shape: the filter below is written against the members they share, not against either
	 * hierarchy.
	 */
	private const HTML5_DOCUMENT_CLASS = 'Dom\\HTMLDocument';

	/** The only element namespace whose tags may survive; anything else is foreign content. */
	private const HTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

	/**
	 * Hard bound on the markup handed to the parser or to the plain-text reducer.
	 *
	 * A single entry's stored content is not bounded by anything the extension controls: it comes
	 * from whatever a publisher put in the feed. The word and sentence limits bound the *output*,
	 * not the cost of parsing the input, and neither does the per-feed article cap. Cutting on a
	 * character boundary keeps the remainder valid UTF-8, and the allow-list filter discards the
	 * partial tag the cut may leave behind.
	 */
	private const MAX_INPUT_BYTES = 262144;

	/** URL schemes accepted for links that a user may navigate to. */
	private const ALLOWED_URL_SCHEMES = ['http', 'https', 'mailto'];

	/** Elements removed together with everything they contain. */
	private const DROPPED_SUBTREES = [
		'applet' => true, 'area' => true, 'audio' => true, 'base' => true, 'button' => true,
		'canvas' => true, 'embed' => true, 'form' => true, 'frame' => true, 'frameset' => true,
		'head' => true, 'iframe' => true, 'input' => true, 'link' => true, 'map' => true,
		'math' => true, 'meta' => true, 'noscript' => true, 'object' => true, 'option' => true,
		'param' => true, 'script' => true, 'select' => true, 'source' => true, 'style' => true,
		'svg' => true, 'template' => true, 'textarea' => true, 'track' => true, 'video' => true,
	];

	/**
	 * Allowed elements mapped to the attributes each may keep.
	 *
	 * `img` is intentionally absent: excerpts must not trigger third-party requests.
	 * `id`, `class` and `style` are never allowed, to avoid DOM clobbering and CSS injection.
	 *
	 * @var array<string,list<string>>
	 */
	private const ALLOWED_ELEMENTS = [
		'a' => ['href', 'title', 'lang', 'dir'],
		'abbr' => ['title'],
		'address' => [],
		'article' => [],
		'b' => [],
		'blockquote' => ['cite'],
		'br' => [],
		'caption' => [],
		'cite' => [],
		'code' => [],
		'col' => ['span'],
		'colgroup' => ['span'],
		'dd' => [],
		'del' => ['cite', 'datetime'],
		'dfn' => ['title'],
		'div' => [],
		'dl' => [],
		'dt' => [],
		'em' => [],
		'figcaption' => [],
		'figure' => [],
		'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
		'hr' => [],
		'i' => [],
		'ins' => ['cite', 'datetime'],
		'kbd' => [],
		'li' => ['value'],
		'mark' => [],
		'ol' => ['start', 'type'],
		'p' => [],
		'pre' => [],
		'q' => ['cite'],
		's' => [],
		'samp' => [],
		'section' => [],
		'small' => [],
		'span' => [],
		'strong' => [],
		'sub' => [],
		'sup' => [],
		'table' => [],
		'tbody' => [],
		'td' => ['colspan', 'rowspan'],
		'tfoot' => [],
		'th' => ['colspan', 'rowspan', 'scope'],
		'thead' => [],
		'time' => ['datetime'],
		'tr' => [],
		'u' => [],
		'ul' => [],
		'var' => [],
	];

	/** Attributes whose value is a URL and therefore needs scheme validation. */
	private const URL_ATTRIBUTES = ['href' => true, 'cite' => true, 'src' => true];

	/**
	 * Validate a URL that will be assigned to a link, and return it in canonical form.
	 *
	 * FreshRSS documents `FreshRSS_Entry::link()` and `FreshRSS_Feed::website()` as HTML-encoded,
	 * so entities are decoded here as URL data before parsing. Only the allow-listed schemes are
	 * accepted; anything else (notably `javascript:` and `data:`) yields an empty string, because
	 * `FreshRSS_Entry::_link()` performs no validation of its own.
	 *
	 * @param list<string> $allowedSchemes Lower-case schemes, without the trailing colon.
	 * @return string The safe URL, or '' when the value must not be used as a link target.
	 */
	public static function safeUrl(string $url, array $allowedSchemes = self::ALLOWED_URL_SCHEMES): string {
		$url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		if ($url === '') {
			return '';
		}

		// Reject control characters, which browsers strip before resolving the scheme.
		if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
			return '';
		}

		$scheme = parse_url($url, PHP_URL_SCHEME);
		if (!is_string($scheme) || $scheme === '') {
			// Protocol-relative and relative URLs have no scheme of their own; only allow the
			// unambiguous absolute forms so a link can never inherit an unexpected scheme.
			return '';
		}

		return in_array(strtolower($scheme), $allowedSchemes, true) ? $url : '';
	}

	/** Whether a value is a six-digit hexadecimal colour such as `#1a2B3c`. */
	public static function isHexColor(string $value): bool {
		return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
	}

	/**
	 * Reduce markup to plain text.
	 *
	 * Tags are removed first and entities decoded afterwards, so text that a feed escaped stays
	 * text instead of becoming markup. The result is only ever safe to assign with `textContent`.
	 */
	public static function toText(string $html): string {
		$html = self::boundInput($html);
		// `strip_tags()` keeps the *content* of script/style blocks, so remove those outright.
		$html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;
		// Give block boundaries a separator so adjacent words do not run together.
		$html = preg_replace('#<(?:br|/p|/div|/li|/h[1-6]|/tr|/blockquote|/figcaption)\b[^>]*>#i', ' ', $html) ?? $html;

		$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		// Collapse the whitespace that stripping block-level tags leaves behind.
		return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
	}

	/** Truncate plain text to at most `$limit` whitespace/comma separated words. */
	public static function truncateWords(string $text, int $limit): string {
		if ($limit < 1) {
			return '';
		}
		$words = preg_split('/[\s,]+/u', $text, $limit + 1, PREG_SPLIT_NO_EMPTY);
		if ($words === false) {
			return $text;
		}
		if (count($words) <= $limit) {
			return $text;
		}
		return implode(' ', array_slice($words, 0, $limit)) . '…';
	}

	/** Truncate plain text to at most `$limit` sentences. */
	public static function truncateSentences(string $text, int $limit): string {
		if ($limit < 1) {
			return '';
		}
		$sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
		if ($sentences === false || count($sentences) <= $limit) {
			return $text;
		}
		return implode(' ', array_slice($sentences, 0, $limit)) . '…';
	}

	/**
	 * Filter markup against the allow-list and truncate it to `$wordLimit` words.
	 *
	 * Truncation happens on the parsed DOM rather than on the string, so a cut can never leave a
	 * half-written tag behind. The return value contains only allow-listed elements and attributes
	 * and only allow-listed URL schemes.
	 */
	public static function sanitizeHtml(string $html, ?int $wordLimit = null): string {
		$html = self::boundInput($html);
		if (trim($html) === '') {
			return '';
		}

		$root = self::loadFragment($html);
		if ($root === null) {
			// Parsing failed; fall back to escaped plain text rather than emitting raw markup.
			$text = self::toText($html);
			if ($wordLimit !== null) {
				$text = self::truncateWords($text, $wordLimit);
			}
			return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}

		self::filterChildren($root);
		if ($wordLimit !== null) {
			self::truncateNodeToWords($root, $wordLimit);
		}

		$doc = $root->ownerDocument;
		if ($doc === null) {
			return '';
		}

		$result = '';
		foreach (iterator_to_array($root->childNodes) as $child) {
			$result .= (string)$doc->saveHTML($child);
		}
		return trim($result);
	}

	/** Cut over-long markup on a character boundary so the parser always sees bounded input. */
	private static function boundInput(string $html): string {
		if (strlen($html) <= self::MAX_INPUT_BYTES) {
			return $html;
		}
		return mb_strcut($html, 0, self::MAX_INPUT_BYTES, 'UTF-8');
	}

	/**
	 * Parse an HTML fragment and return the single known root element wrapping it.
	 *
	 * PHP 8.4 added `Dom\HTMLDocument`, an HTML5 parser that follows the same specification the
	 * browser does. That matters here because the result of this parse is eventually assigned to
	 * `innerHTML`: where the two parsers disagree — foreign content, raw-text elements, mis-nested
	 * tables — the browser's reading is the one that decides what actually runs, and PHP documents
	 * that very divergence as a reason not to rely on `DOMDocument::loadHTML()` for sanitisation.
	 * It is used when available; older runtimes keep the `DOMDocument` path.
	 *
	 * Either way the filter is an allow-list over the parsed tree and the output is re-serialised
	 * with escaping, so a parser disagreement can change which text survives but cannot introduce
	 * an element or attribute that the allow-list does not name.
	 *
	 * @see https://www.php.net/manual/en/domdocument.loadhtml.php
	 * @return object|null A `DOMElement` or a `Dom\Element`, whichever parser ran.
	 */
	private static function loadFragment(string $html): ?object {
		return self::loadFragmentHtml5($html) ?? self::loadFragmentLibxml($html);
	}

	/** Spec-compliant HTML5 parse, used on PHP 8.4 and later. Null when unavailable or unparsable. */
	private static function loadFragmentHtml5(string $html): ?object {
		$factory = self::HTML5_DOCUMENT_CLASS . '::createFromString';
		if (!class_exists(self::HTML5_DOCUMENT_CLASS) || !is_callable($factory)) {
			return null;
		}

		try {
			// Nothing is appended after the fragment. A raw-text element such as `<plaintext>` or
			// `<xmp>` runs to the end of the input, so any closing tag written after it would be
			// swallowed and re-emitted as visible text in the excerpt.
			$doc = $factory('<!DOCTYPE html><body>' . $html, LIBXML_NOERROR, 'UTF-8');
		} catch (\Throwable $e) {
			return null;
		}

		if (!is_object($doc)) {
			return null;
		}
		$body = $doc->body;
		return is_object($body) ? $body : null;
	}

	/** libxml HTML4 parse, used when the HTML5 parser is unavailable. */
	private static function loadFragmentLibxml(string $html): ?object {
		// Remove raw script/style blocks before parsing. Per the HTML spec their content ends only
		// at the matching close tag, but libxml treats an inner `</p>` as closing the enclosing
		// element, which splits the block and leaks its tail into the document as visible text.
		// Stripping first means the parser never sees the mis-nesting.
		$html = preg_replace('#<(script|style)\b[^>]*>.*?(?:</\1\s*>|$)#is', '', $html) ?? $html;

		$doc = new DOMDocument('1.0', 'UTF-8');
		$previous = libxml_use_internal_errors(true);
		// The XML declaration pins the encoding; without it libxml assumes ISO-8859-1.
		// `LIBXML_HTML_NOIMPLIED` keeps the explicit `<body>` as the document element, which then
		// serves as the fragment root — again with no trailing tag for a raw-text element to eat.
		$loaded = $doc->loadHTML(
			'<?xml encoding="utf-8" ?><body>' . $html,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if (!$loaded) {
			return null;
		}

		$root = $doc->documentElement;
		return $root instanceof DOMElement && strtolower($root->nodeName) === 'body' ? $root : null;
	}

	/**
	 * Recursively apply the allow-list to every child of `$parent`.
	 *
	 * Written against the node interface both DOM implementations share, and against the numeric
	 * node types rather than class names, because `DOMElement` and `Dom\Element` are unrelated
	 * classes with the same shape.
	 *
	 * @param object $parent A `DOMNode` or a `Dom\Node`.
	 */
	private static function filterChildren(object $parent): void {
		// Iterate over a snapshot: the live NodeList shifts as nodes are removed or unwrapped.
		foreach (iterator_to_array($parent->childNodes) as $child) {
			if ($child->nodeType === XML_TEXT_NODE) {
				continue;
			}

			// Comments, processing instructions, CDATA and doctypes carry no displayable text and
			// are exactly the constructs whose reparsing differs between implementations.
			if ($child->nodeType !== XML_ELEMENT_NODE) {
				$parent->removeChild($child);
				continue;
			}

			// Foreign content — SVG and MathML — has its own parsing rules, and an element there
			// may share a local name with an allow-listed HTML element. The HTML5 parser records
			// the namespace, so anything outside the HTML namespace is dropped with its subtree.
			$namespace = $child->namespaceURI;
			if ($namespace !== null && $namespace !== self::HTML_NAMESPACE) {
				$parent->removeChild($child);
				continue;
			}

			$tag = strtolower($child->localName ?? $child->nodeName);

			if (isset(self::DROPPED_SUBTREES[$tag])) {
				$parent->removeChild($child);
				continue;
			}

			if (!isset(self::ALLOWED_ELEMENTS[$tag])) {
				// Unknown but harmless wrapper: keep the text, discard the element.
				self::filterChildren($child);
				self::unwrap($child, $parent);
				continue;
			}

			self::filterAttributes($child, $tag);
			self::filterChildren($child);
		}
	}

	/**
	 * Strip every attribute the element is not explicitly allowed to keep.
	 *
	 * @param object $element A `DOMElement` or a `Dom\Element`.
	 */
	private static function filterAttributes(object $element, string $tag): void {
		$allowed = self::ALLOWED_ELEMENTS[$tag];

		foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
			$name = strtolower($attribute->nodeName);

			// A namespaced attribute such as `xlink:href` is never on an allow-list, but removing
			// it needs the name the parser recorded, not the lower-cased comparison key.
			if (!in_array($name, $allowed, true)) {
				$element->removeAttribute($attribute->nodeName);
				continue;
			}

			if (isset(self::URL_ATTRIBUTES[$name])) {
				$safe = self::safeUrl($attribute->nodeValue ?? '');
				if ($safe === '') {
					$element->removeAttribute($attribute->nodeName);
				} else {
					$element->setAttribute($name, $safe);
				}
			}
		}

		// Surviving links open externally and must not hand the opener over.
		if ($tag === 'a' && $element->hasAttribute('href')) {
			$element->setAttribute('target', '_blank');
			$element->setAttribute('rel', 'noopener noreferrer nofollow');
		}
	}

	/**
	 * Replace an element with its children.
	 *
	 * @param object $element A `DOMElement` or a `Dom\Element`.
	 * @param object $parent Its parent node.
	 */
	private static function unwrap(object $element, object $parent): void {
		foreach (iterator_to_array($element->childNodes) as $grandChild) {
			$parent->insertBefore($grandChild, $element);
		}
		$parent->removeChild($element);
	}

	/**
	 * Trim the tree so it holds at most `$limit` words, appending an ellipsis when text was cut.
	 *
	 * @param object $node A `DOMNode` or a `Dom\Node`.
	 * @return int The number of words kept.
	 */
	private static function truncateNodeToWords(object $node, int $limit, int $used = 0): int {
		foreach (iterator_to_array($node->childNodes) as $child) {
			if ($used >= $limit) {
				$node->removeChild($child);
				continue;
			}

			if ($child->nodeType === XML_TEXT_NODE) {
				$text = $child->nodeValue ?? '';
				$words = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
				$kept = '';
				foreach ($words as $piece) {
					if (trim($piece) === '') {
						$kept .= $piece;
						continue;
					}
					if ($used >= $limit) {
						$kept = rtrim($kept) . '…';
						break;
					}
					$kept .= $piece;
					$used++;
				}
				$child->nodeValue = $kept;
				continue;
			}

			$used = self::truncateNodeToWords($child, $limit, $used);
		}

		return $used;
	}
}
