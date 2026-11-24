<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// Shared by the bug report and Intakt's Evidence lines, because both must read a line of a body as GitHub shows it: code, quoted outside text and comments are no heading and no part of the text's own statement.
final readonly class ReadableLines
{
    private const string LIST_CONTENT = '/^[ \t]*(?:(?:[-*+]|\d{1,9}[.)])(?:[ \t]+|$))+/';

    private const string FENCE_OPENS = '/^(?<lead>[ \t]*(?<markers>(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)*))(?<fence>`{3,}|~{3,})(?<info>.*)$/';

    private const string EXTERNAL_OPENS = '/^[ \t]*<!-- external -->[ \t]*$/';

    private const string EXTERNAL_CLOSES = '/^[ \t]*<!-- \/external -->[ \t]*$/';

    // Inline code spans are matched too and kept, because a `<!--` between backticks is shown as written and opens no comment.
    private const string COMMENT_OR_CODE = '/(?<code>(?<!`)(?<ticks>`+)(?!`).*?(?<!`)\k<ticks>(?!`))|<!--(?:.*?-->|(?<open>.*))/';

    // The text after the closing marker stays, because GitHub shows it.
    private const string COMMENT_CLOSES = '/-->(?<rest>.*)$/';

    /**
     * @param list<string> $lines  one per line of the body, empty where the line is code, outside text or a comment
     * @param ?string      $closer the line that closes the block the body ends in, or null when it ends in none
     */
    private function __construct(
        public array $lines,
        public ?string $closer,
    ) {
    }

    // Read in the order they open, because a fence inside a comment or a comment marker inside a fence is the other block's text.
    public static function of(string $body): self
    {
        $kept = [];
        $block = null;
        $lists = [];

        foreach (explode("\n", self::withLineFeeds($body)) as $line) {
            $block = self::stillOpen($line, $block);

            if (null !== $block) {
                [$kept[], $block] = self::closedIn($line, $block);

                continue;
            }

            $lists = self::listsAfter($line, $lists);
            [$kept[], $block] = self::openedIn($line, array_last($lists) ?? 0);
        }

        return new self($kept, $block[3] ?? null);
    }

    public static function withLineFeeds(string $body): string
    {
        return str_replace(["\r\n", "\r"], "\n", $body);
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

        return null === $block ? self::withoutComments($line) : ['', $block];
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

    private static function indentOf(string $line): int
    {
        return self::widthOf(substr($line, 0, strspn($line, " \t")));
    }

    private static function widthOf(string $text): int
    {
        return strlen(str_replace("\t", '    ', $text));
    }
}
