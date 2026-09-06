<?php

declare(strict_types=1);

namespace tryallthethings\FreshVibes\Models;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;
use DOMText;
use DOMXPath;

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

	/** Wrapper element used to give libxml a single, predictable root. */
	private const ROOT_ID = 'freshvibes-sanitizer-root';

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
		if (trim($html) === '') {
			return '';
		}

		$doc = self::loadFragment($html);
		if ($doc === null) {
			// Parsing failed; fall back to escaped plain text rather than emitting raw markup.
			$text = self::toText($html);
			if ($wordLimit !== null) {
				$text = self::truncateWords($text, $wordLimit);
			}
			return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}

		$xpath = new DOMXPath($doc);
		$root = $xpath->query('//*[@id="' . self::ROOT_ID . '"]')->item(0);
		if (!$root instanceof DOMElement) {
			return '';
		}

		self::filterChildren($root);
		if ($wordLimit !== null) {
			self::truncateNodeToWords($root, $wordLimit);
		}

		$result = '';
		foreach (iterator_to_array($root->childNodes) as $child) {
			$result .= $doc->saveHTML($child);
		}
		return trim($result);
	}

	/** Parse an HTML fragment into a document with a single known root element. */
	private static function loadFragment(string $html): ?DOMDocument {
		// Remove raw script/style blocks before parsing. Per the HTML spec their content ends only
		// at the matching close tag, but libxml treats an inner `</p>` as closing the enclosing
		// element, which splits the block and leaks its tail into the document as visible text.
		// Stripping first means the parser never sees the mis-nesting.
		$html = preg_replace('#<(script|style)\b[^>]*>.*?(?:</\1\s*>|$)#is', '', $html) ?? $html;

		$doc = new DOMDocument('1.0', 'UTF-8');
		$previous = libxml_use_internal_errors(true);
		// The XML declaration pins the encoding; without it libxml assumes ISO-8859-1.
		$loaded = $doc->loadHTML(
			'<?xml encoding="utf-8" ?><div id="' . self::ROOT_ID . '">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		return $loaded ? $doc : null;
	}

	/** Recursively apply the allow-list to every child of `$parent`. */
	private static function filterChildren(DOMNode $parent): void {
		// Iterate over a snapshot: the live NodeList shifts as nodes are removed or unwrapped.
		foreach (iterator_to_array($parent->childNodes) as $child) {
			if ($child instanceof DOMText) {
				continue;
			}

			if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
				$parent->removeChild($child);
				continue;
			}

			if (!$child instanceof DOMElement) {
				$parent->removeChild($child);
				continue;
			}

			$tag = strtolower($child->nodeName);

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

	/** Strip every attribute the element is not explicitly allowed to keep. */
	private static function filterAttributes(DOMElement $element, string $tag): void {
		$allowed = self::ALLOWED_ELEMENTS[$tag];

		foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
			$name = strtolower($attribute->nodeName);

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

	/** Replace an element with its children. */
	private static function unwrap(DOMElement $element, DOMNode $parent): void {
		foreach (iterator_to_array($element->childNodes) as $grandChild) {
			$parent->insertBefore($grandChild, $element);
		}
		$parent->removeChild($element);
	}

	/**
	 * Trim the tree so it holds at most `$limit` words, appending an ellipsis when text was cut.
	 *
	 * @return int The number of words kept.
	 */
	private static function truncateNodeToWords(DOMNode $node, int $limit, int $used = 0): int {
		foreach (iterator_to_array($node->childNodes) as $child) {
			if ($used >= $limit) {
				$node->removeChild($child);
				continue;
			}

			if ($child instanceof DOMText) {
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
