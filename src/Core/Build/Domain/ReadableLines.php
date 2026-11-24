<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// Shared by the bug report and Intakt's Evidence lines, because both must read a line of a body as GitHub shows it: code, quoted outside text, raw HTML and comments are no heading and no part of the text's own statement.
final readonly class ReadableLines
{
    // Also after list markers, because a heading in a list item still starts a new section.
    public const string HEADING = '/^(?: {0,3}|[ \t]*(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)+)#{1,6}(?:[ \t]+(?<text>.*?)[ \t]*:?)?(?:[ \t]+#+)?[ \t]*$/';

    private const string UNDERLINE = '/^ {0,3}(?:=+|-+)[ \t]*$/';

    private const string LIST_ITEM = '/^[ \t]*(?:[-*+]|\d{1,9}[.)])(?:[ \t]|$)/';

    private const string LIST_CONTENT = '/^[ \t]*(?:(?:[-*+]|\d{1,9}[.)])(?:[ \t]+|$))+/';

    private const string FENCE_OPENS = '/^(?<lead>[ \t]*(?<markers>(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)*))(?<fence>`{3,}|~{3,})(?<info>.*)$/';

    private const string EXTERNAL_OPENS = '/^[ \t]*<!-- external -->[ \t]*$/';

    private const string EXTERNAL_CLOSES = '/^[ \t]*<!-- \/external -->[ \t]*$/';

    // Inline code spans are matched too and kept, because a `<!--` between backticks is shown as written and opens no comment.
    private const string COMMENT_OR_CODE = '/(?<code>(?<!`)(?<ticks>`+)(?!`).*?(?<!`)\k<ticks>(?!`))|<!--(?:.*?-->|(?<open>.*))/';

    // The text after the closing marker stays, because GitHub shows it.
    private const string COMMENT_CLOSES = '/-->(?<rest>.*)$/';

    // One line per line of the body, empty where it is code, outside text, raw HTML or a comment, an underlined heading written as `#` or `##` on its first line; the content column of the list item each line is in, 0 outside any list; and the closer of the block the body ends in, so a writer can close it before adding lines (empty for a block only a blank line ends).
    /**
     * @param list<string> $lines
     * @param list<int>    $columns
     */
    private function __construct(
        public array $lines,
        public array $columns,
        public ?string $closer,
    ) {
    }

    // Read in the order they open, because a fence inside a comment or a comment marker inside a fence is the other block's text.
    public static function of(string $body): self
    {
        $kept = [];
        $columns = [];
        $block = null;
        $lists = [];
        $paragraph = false;

        foreach (explode("\n", self::withLineFeeds($body)) as $line) {
            $block = self::stillOpen($line, $block);

            if (null !== $block) {
                $columns[] = $block[1];
                [$kept[], $block] = self::closedIn($line, $block);
                $paragraph = false;

                continue;
            }

            $lists = self::listsAfter($line, $lists);
            $container = array_last($lists) ?? 0;
            $columns[] = $container;
            // A lone tag inside a paragraph stays text, because CommonMark lets no such HTML block interrupt one.
            [$kept[], $block] = $paragraph ? self::openedIn($line, $container) : self::openedOutsideAParagraphIn($line, $container);
            $paragraph = null === $block && self::isParagraphText(array_last($kept)) && ($paragraph || !self::isIndentedCode($line, $container));
        }

        return new self(self::withUnderlinedHeadingsMarked($kept, $columns), $columns, $block[3] ?? null);
    }

    public static function withLineFeeds(string $body): string
    {
        return str_replace(["\r\n", "\r"], "\n", $body);
    }

    public static function isIndentedCode(string $line, int $container): bool
    {
        return self::indentOf($line) - $container > 3;
    }

    /**
     * @param ?array{string, int, int, string} $block
     *
     * @return ?array{string, int, int, string}
     */
    private static function stillOpen(string $line, ?array $block): ?array
    {
        // A block in a list item ends with the item, because CommonMark closes every block the item holds.
        return null !== $block && '' !== trim($line) && self::indentOf($line) < $block[1] ? null : $block;
    }

    /**
     * @param array{string, int, int, string} $block
     *
     * @return array{string, ?array{string, int, int, string}}
     */
    private static function closedIn(string $line, array $block): array
    {
        if (1 !== preg_match($block[0], $line, $found) || self::indentOf($line) - $block[1] > $block[2]) {
            return ['', $block];
        }

        return isset($found['rest']) ? self::withoutComments($found['rest']) : ['', null];
    }

    /**
     * @return array{string, ?array{string, int, int, string}}
     */
    private static function withoutComments(string $line): array
    {
        $opens = false;
        $line = (string) preg_replace_callback(self::COMMENT_OR_CODE, static function (array $found) use (&$opens): string {
            $opens = $opens || isset($found['open']);

            return $found['code'] ?? '';
        }, $line, flags: PREG_UNMATCHED_AS_NULL);

        return [$line, $opens ? [self::COMMENT_CLOSES, 0, PHP_INT_MAX, '-->'] : null];
    }

    /**
     * @param list<int> $lists
     *
     * @return list<int>
     */
    private static function listsAfter(string $line, array $lists): array
    {
        if ('' === trim($line)) {
            return $lists;
        }

        $indent = self::indentOf($line);
        $lists = array_values(array_filter($lists, static fn (int $column): bool => $column <= $indent));

        if (1 === preg_match(self::LIST_CONTENT, $line, $found)) {
            $lists[] = self::widthOf($found[0]);
        }

        return $lists;
    }

    /**
     * @return array{string, ?array{string, int, int, string}}
     */
    private static function openedIn(string $line, int $container): array
    {
        $block = self::blockOpenedBy($line, $container);

        if (null !== $block) {
            return ['', $block];
        }

        $html = HtmlBlocks::openedBy($line, $container);

        if (null === $html) {
            return self::withoutComments($line);
        }

        // A raw block may end on its opening line, because CommonMark ends it on the line that holds its end tag.
        return ['', 1 === preg_match($html[0], $line) ? null : $html];
    }

    /**
     * @return ?array{string, int, int, string}
     */
    private static function blockOpenedBy(string $line, int $container): ?array
    {
        if (1 === preg_match(self::EXTERNAL_OPENS, $line)) {
            return [self::EXTERNAL_CLOSES, 0, PHP_INT_MAX, '<!-- /external -->'];
        }

        if (1 !== preg_match(self::FENCE_OPENS, $line, $found)) {
            return null;
        }

        $column = self::widthOf($found['lead']);
        $container = '' === $found['markers'] ? $container : $column;

        // No fence four columns into its container, or when more backticks follow, because CommonMark reads those as indented code or an inline code span.
        if ($column - $container > 3 || ('`' === $found['fence'][0] && str_contains($found['info'], '`'))) {
            return null;
        }

        // A fence closes only with the same character at least as often, because CommonMark lets a longer fence hold a shorter one, and at most three columns into its container, because a deeper closer is the code's own text; its closer is indented to the container, because one further out would end the list item and open a fence of its own.
        return [sprintf('/^[ \t]*%s{%d,}[ \t]*$/', preg_quote($found['fence'][0], '/'), strlen($found['fence'])), $container, 3, str_repeat(' ', $container).$found['fence']];
    }

    /**
     * @return array{string, ?array{string, int, int, string}}
     */
    private static function openedOutsideAParagraphIn(string $line, int $container): array
    {
        $opened = self::openedIn($line, $container);
        $tag = null === $opened[1] ? HtmlBlocks::openedByALoneTag($line, $container) : null;

        return null === $tag ? $opened : ['', $tag];
    }

    // An underline, a heading or a blank line is no paragraph text, because each ends the paragraph above it.
    private static function isParagraphText(string $kept): bool
    {
        return '' !== trim($kept) && 1 !== preg_match(self::HEADING, $kept) && 1 !== preg_match(self::UNDERLINE, $kept);
    }

    /**
     * @param list<string> $lines
     * @param list<int>    $columns
     *
     * @return list<string>
     */
    private static function withUnderlinedHeadingsMarked(array $lines, array $columns): array
    {
        $start = null;
        $listItem = -1;

        foreach ($lines as $index => $line) {
            $heading = self::underlinedFrom($line, $start, $listItem);

            if (null !== $heading) {
                array_splice($lines, $heading, $index - $heading + 1, self::headingOf(array_slice($lines, $heading, $index - $heading + 1)));
                $start = null;

                continue;
            }

            // Indented code starts no paragraph, because CommonMark makes no heading of code above an underline.
            $start = match (true) {
                '' === trim($line), 1 === preg_match(self::HEADING, $line) => null,
                null === $start && self::isIndentedCode($line, $columns[$index]) => null,
                default => $start ?? $index,
            };
            $listItem = 1 === preg_match(self::LIST_ITEM, $line) ? $index : $listItem;
        }

        return $lines;
    }

    private static function underlinedFrom(string $line, ?int $start, int $listItem): ?int
    {
        // An underline makes a heading only of the paragraph right above it, because after a blank line, a heading or in a paragraph with a list item it is a rule.
        return null !== $start && $listItem < $start && 1 === preg_match(self::UNDERLINE, $line) ? $start : null;
    }

    // The level stays, because `=` underlines a first-level and `-` a second-level heading, and only one of them may be the Evidence heading.
    /**
     * @param list<string> $paragraph
     *
     * @return list<string>
     */
    private static function headingOf(array $paragraph): array
    {
        $underline = (string) array_pop($paragraph);

        return [(str_contains($underline, '=') ? '#' : '##').' '.implode(' ', array_map(trim(...), $paragraph)), ...array_fill(0, count($paragraph), '')];
    }

    private static function indentOf(string $line): int
    {
        return self::widthOf(substr($line, 0, strspn($line, " \t")));
    }

    private static function widthOf(string $text): int
    {
        return strlen(str_replace("\t", '    ', $text));
    }
}
