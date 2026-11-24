<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// Apart from the other blocks, because CommonMark's seven HTML block kinds each start and end by their own rule.
final readonly class HtmlBlocks
{
    private const string OPENS = '/^[ \t]*<(?<slash>\/?)(?<tag>[a-zA-Z][a-zA-Z0-9-]*)(?:[ \t>]|\/>|$)/';

    // A complete open or closing tag alone on its line, because CommonMark opens an HTML block with any such tag that is not raw text.
    private const string LONE_TAG = '/^[ \t]*<(?<slash>\/)?(?<tag>[a-zA-Z][a-zA-Z0-9-]*)(?(slash)[ \t]*|(?:[ \t]+[a-zA-Z_:][a-zA-Z0-9_.:-]*(?:[ \t]*=[ \t]*(?:[^ \t"\'=<>`]+|\'[^\']*\'|"[^"]*"))?)*[ \t]*\/?)>[ \t]*$/';

    private const string BLANK_LINE = '/^[ \t]*$/';

    // Raw text up to their end tag, because CommonMark shows a heading or a fence inside them as written.
    private const array RAW_HTML = ['pre', 'script', 'style', 'textarea'];

    // Raw text up to the next blank line, because CommonMark ends the HTML blocks these tags open there.
    private const array BLOCK_TAGS = ['address', 'article', 'aside', 'base', 'basefont', 'blockquote', 'body', 'caption', 'center', 'col', 'colgroup', 'dd', 'details', 'dialog', 'dir', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'frame', 'frameset', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hr', 'html', 'iframe', 'legend', 'li', 'link', 'main', 'menu', 'menuitem', 'nav', 'noframes', 'ol', 'optgroup', 'option', 'p', 'param', 'search', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'title', 'tr', 'track', 'ul'];

    /**
     * @return ?array{string, int, int, string}
     */
    public static function openedBy(string $line, int $container): ?array
    {
        if (1 !== preg_match(self::OPENS, $line, $found) || ReadableLines::isIndentedCode($line, $container)) {
            return null;
        }

        $tag = strtolower($found['tag']);

        // Any of the four end tags ends the raw block, because CommonMark does not match it to the tag that opened it.
        if ('' === $found['slash'] && in_array($tag, self::RAW_HTML, true)) {
            return [sprintf('/<\/(?:%s)>/i', implode('|', self::RAW_HTML)), $container, PHP_INT_MAX, str_repeat(' ', $container).'</'.$tag.'>'];
        }

        return in_array($tag, self::BLOCK_TAGS, true) ? self::endedByABlankLine($container) : null;
    }

    // Only where no paragraph is open, because CommonMark lets no such block interrupt one.
    /**
     * @return ?array{string, int, int, string}
     */
    public static function openedByALoneTag(string $line, int $container): ?array
    {
        if (1 !== preg_match(self::LONE_TAG, $line, $found) || ReadableLines::isIndentedCode($line, $container) || in_array(strtolower($found['tag']), self::RAW_HTML, true)) {
            return null;
        }

        return self::endedByABlankLine($container);
    }

    /**
     * @return array{string, int, int, string}
     */
    private static function endedByABlankLine(int $container): array
    {
        return [self::BLANK_LINE, $container, PHP_INT_MAX, ''];
    }
}
