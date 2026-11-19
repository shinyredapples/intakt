<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

use RuntimeException;

// Both the task and the pull request text are judged by what the forge renders, so text a reader never sees (an HTML comment) must not count, while a fenced block is shown but is code, not structure.
final readonly class MarkdownText
{
    // One pass from left to right, because whichever of a fence, a code span and a comment opens first makes the others' markers literal (https://spec.commonmark.org/0.31.2/#fenced-code-blocks, https://spec.commonmark.org/0.31.2/#code-spans); a fence closes only with a run of its own character at least as long, a code span only with a backtick run of the same length in the same paragraph.
    private const string HIDDEN_OR_LITERAL = '/(?<fence>^ {0,3}(?:(?<tick>`{3,})[^`\n]*|(?<tilde>~{3,})[^\n]*)(?:\n[\s\S]*?)??(?:\n {0,3}(?:\k<tick>`*|\k<tilde>~*)[ \t]*(?=\n|\z)|\z))|(?<code>(?<!`)(?<run>`+)(?!`)(?:(?!\n[ \t]*\n)[\s\S])*?(?<!`)\k<run>(?!`))|(?<comment><!--[\s\S]*?(?:-->|\z))/m';

    private const string LIST_ITEM = '/^( *)([-*+]|\d{1,9}[.)])( +)\S/';

    private const string THEMATIC_BREAK = '/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/';

    private const string INDENT_AND_MARKER = '/^[ \t]*(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)?/';

    private const int TAB_STOP = 4;

    public static function visible(string $markdown): string
    {
        return self::read($markdown, withFences: true);
    }

    public static function visibleProse(string $markdown): string
    {
        return self::read($markdown, withFences: false);
    }

    // A tab in the indent or after a list marker runs to the next tab stop, as Markdown reads it (https://spec.commonmark.org/0.31.2/#tabs).
    public static function withSpacesForTabs(string $line): string
    {
        return preg_replace_callback(self::INDENT_AND_MARKER, static fn (array $match): string => self::atTabStops($match[0]), $line) ?? $line;
    }

    public static function listItemColumnOf(string $line): ?int
    {
        if (1 === preg_match(self::THEMATIC_BREAK, $line) || 1 !== preg_match(self::LIST_ITEM, $line, $match)) {
            return null;
        }

        // Five or more spaces after the marker start indented code inside the item, so its content begins one space after the marker.
        return strlen($match[1]) + strlen($match[2]) + (strlen($match[3]) > 4 ? 1 : strlen($match[3]));
    }

    private static function read(string $markdown, bool $withFences): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);
        $read = '';
        $offset = 0;

        while (1 === ($found = preg_match(self::HIDDEN_OR_LITERAL, $text, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset))) {
            [$part, $start] = $match[0];
            $isFence = null !== $match['fence'][0];
            $part = $isFence ? self::untilItsListItemEnds(substr($text, 0, $start), $part) : $part;
            $isShown = null !== $match['code'][0] || ($isFence && $withFences);
            $read .= substr($text, $offset, $start - $offset).($isShown ? $part : self::lineBreaksOf($part));
            $offset = $start + strlen($part);
        }

        // An unreadable text must stop the check, because reading it as empty would pass a task's Evidence list unseen.
        return false === $found
            ? throw new RuntimeException('the Markdown text cannot be read: '.preg_last_error_msg()) : $read.substr($text, $offset);
    }

    // A fence opened inside a list item ends with that item, closed or not, so the first line indented less than the item's content is outside it and is read again.
    private static function untilItsListItemEnds(string $before, string $fence): string
    {
        $column = self::columnOfTheItemAround($before, strspn($fence, ' '));

        if (null === $column) {
            return $fence;
        }

        return 1 === preg_match('/\n(?= {0,'.($column - 1).'}\S)/', $fence, $match, PREG_OFFSET_CAPTURE) ? substr($fence, 0, $match[0][1]) : $fence;
    }

    // The item a line belongs to is the nearest list item above whose content column the line reaches; a less indented line in between that is no such item has ended every deeper item.
    private static function columnOfTheItemAround(string $before, int $indent): ?int
    {
        foreach (array_reverse(explode("\n", $before)) as $rawLine) {
            if (0 === $indent) {
                return null;
            }

            $line = self::withSpacesForTabs($rawLine);
            $lineIndent = strspn($line, ' ');

            if ('' === trim($line) || $lineIndent > $indent) {
                continue;
            }

            $column = self::listItemColumnOf($line);

            if (null !== $column && $column <= $indent) {
                return $column;
            }

            $indent = $lineIndent;
        }

        return null;
    }

    private static function atTabStops(string $prefix): string
    {
        return array_reduce(
            str_split($prefix),
            static fn (string $expanded, string $character): string => $expanded.("\t" === $character ? str_repeat(' ', self::TAB_STOP - strlen($expanded) % self::TAB_STOP) : $character),
            '',
        );
    }

    // Hidden and fenced text keeps its line breaks, because the lines around it keep their place.
    private static function lineBreaksOf(string $text): string
    {
        return str_repeat("\n", substr_count($text, "\n"));
    }
}
