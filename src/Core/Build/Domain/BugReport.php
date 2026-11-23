<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// Plain code reads the shape, because a model must not decide whether a bug task is ready to build.
final readonly class BugReport
{
    // Also after list markers, because a heading in a list item still starts a new section.
    private const string HEADING = '/^(?: {0,3}|[ \t]*(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)+)#{1,6}(?:[ \t]+(?<text>.*?)[ \t]*:?)?(?:[ \t]+#+)?[ \t]*$/';

    private const string UNDERLINE = '/^ {0,3}(?:=+|-+)[ \t]*$/';

    private const string LIST_ITEM = '/^[ \t]*(?:[-*+]|\d{1,9}[.)])(?:[ \t]|$)/';

    private const string LIST_CONTENT = '/^[ \t]*(?:(?:[-*+]|\d{1,9}[.)])(?:[ \t]+|$))+/';

    private const string BOLD_LABEL = '/^[ \t]*(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)?(?<mark>\*\*|__)(?<label>.+?)[ \t]*:?[ \t]*\k<mark>[ \t]*:?[ \t]*(?<rest>.*)$/';

    private const string FENCE_OPENS = '/^(?<lead>[ \t]*(?<markers>(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)*))(?<fence>`{3,}|~{3,})(?<info>.*)$/';

    private const string EXTERNAL_OPENS = '/^[ \t]*<!-- external -->[ \t]*$/';

    private const string EXTERNAL_CLOSES = '/^[ \t]*<!-- \/external -->[ \t]*$/';

    private const string COMMENT = '/<!--.*?-->/';

    // The text after the closing marker stays, because GitHub shows it.
    private const string COMMENT_CLOSES = '/-->(?<rest>.*)$/';

    /**
     * @param array<string, string> $texts
     */
    private function __construct(private array $texts)
    {
    }

    public static function fromBody(string $body): self
    {
        $texts = array_fill_keys(array_column(BugPart::cases(), 'value'), '');
        $current = null;

        foreach (self::withUnderlinedHeadingsMarked(self::linesToRead($body)) as $line) {
            $label = self::labelOf($line);

            // Any heading or bold label ends the part, also one of another section, so its text never counts as the part's.
            if (null !== $label) {
                $current = self::partNamed($label[0])?->value;
                $line = $label[1];
            }

            if (null !== $current) {
                $texts[$current] .= $line."\n";
            }
        }

        return new self($texts);
    }

    /**
     * @return list<BugPart>
     */
    public function missingParts(): array
    {
        return array_values(array_filter(BugPart::cases(), fn (BugPart $part): bool => '' === trim($this->texts[$part->value])));
    }

    /**
     * @return list<string>
     */
    private static function linesToRead(string $body): array
    {
        $kept = [];
        $block = null;
        $lists = [];

        // Without code, quoted outside text and comments, read in the order they open, because a part written there is an example or a quote, not the task's own statement.
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $body)) as $line) {
            $block = self::stillOpen($line, $block);

            if (null !== $block) {
                [$kept[], $block] = self::closedIn($line, $block);

                continue;
            }

            $lists = self::listsAfter($line, $lists);
            [$kept[], $block] = self::openedIn($line, array_last($lists) ?? 0);
        }

        return $kept;
    }

    /**
     * @param ?array{string, int} $block
     *
     * @return ?array{string, int}
     */
    private static function stillOpen(string $line, ?array $block): ?array
    {
        // A block in a list item ends with the item, because CommonMark closes every block the item holds.
        return null !== $block && '' !== trim($line) && self::indentOf($line) < $block[1] ? null : $block;
    }

    /**
     * @param array{string, int} $block
     *
     * @return array{string, ?array{string, int}}
     */
    private static function closedIn(string $line, array $block): array
    {
        if (1 !== preg_match($block[0], $line, $found)) {
            return ['', $block];
        }

        return isset($found['rest']) ? self::withoutComments($found['rest']) : ['', null];
    }

    /**
     * @return array{string, ?array{string, int}}
     */
    private static function withoutComments(string $line): array
    {
        $line = (string) preg_replace(self::COMMENT, '', $line);
        $opens = strpos($line, '<!--');

        return false === $opens ? [$line, null] : [substr($line, 0, $opens), [self::COMMENT_CLOSES, 0]];
    }

    /**
     * The content columns of the list items the line is in, innermost last.
     *
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
     * @return array{string, ?array{string, int}}
     */
    private static function openedIn(string $line, int $container): array
    {
        $block = self::blockOpenedBy($line, $container);

        return null === $block ? self::withoutComments($line) : ['', $block];
    }

    /**
     * @return ?array{string, int}
     */
    private static function blockOpenedBy(string $line, int $container): ?array
    {
        if (1 === preg_match(self::EXTERNAL_OPENS, $line)) {
            return [self::EXTERNAL_CLOSES, 0];
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

        // A fence closes only with the same character at least as often, because CommonMark lets a longer fence hold a shorter one.
        return [sprintf('/^[ \t]*%s{%d,}[ \t]*$/', preg_quote($found['fence'][0], '/'), strlen($found['fence'])), $container];
    }

    private static function indentOf(string $line): int
    {
        return self::widthOf(substr($line, 0, strspn($line, " \t")));
    }

    private static function widthOf(string $text): int
    {
        return strlen(str_replace("\t", '    ', $text));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function withUnderlinedHeadingsMarked(array $lines): array
    {
        $start = null;

        foreach ($lines as $index => $line) {
            if (is_int($start) && 1 === preg_match(self::UNDERLINE, $line)) {
                $text = implode(' ', array_map(trim(...), array_slice($lines, $start, $index - $start)));
                array_splice($lines, $start, $index - $start + 1, ['# '.$text, ...array_fill(0, $index - $start, '')]);
                $start = null;

                continue;
            }

            $start = self::paragraphAfter($line, $index, $start);
        }

        return $lines;
    }

    // An underline makes a heading only of the paragraph right above it, because after a blank line, a heading or a list item it is a rule; false marks a paragraph no underline can turn into a heading.
    private static function paragraphAfter(string $line, int $index, int|false|null $start): int|false|null
    {
        if ('' === trim($line) || 1 === preg_match(self::HEADING, $line)) {
            return null;
        }

        return 1 === preg_match(self::LIST_ITEM, $line) ? false : $start ?? $index;
    }

    /**
     * @return ?array{string, string}
     */
    private static function labelOf(string $line): ?array
    {
        if (1 === preg_match(self::HEADING, $line, $found)) {
            return [$found['text'] ?? '', ''];
        }

        return 1 === preg_match(self::BOLD_LABEL, $line, $found) ? [$found['label'], $found['rest']] : null;
    }

    private static function partNamed(string $label): ?BugPart
    {
        return array_find(BugPart::cases(), static fn (BugPart $part): bool => 0 === strcasecmp($part->value, $label));
    }
}
