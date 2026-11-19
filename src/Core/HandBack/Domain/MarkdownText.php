<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

use RuntimeException;

// Both the task and the pull request text are judged by what the forge renders, so text a reader never sees (an HTML comment) must not count, while a fenced block is shown but is code, not structure.
final readonly class MarkdownText
{
    // One pass from left to right, because whichever of a fence, a code span and a comment opens first makes the others' markers literal (https://spec.commonmark.org/0.31.2/#fenced-code-blocks, https://spec.commonmark.org/0.31.2/#code-spans). The pattern finds only where a part opens; each end is searched with plain string functions, because a pattern that walks a long part character by character exhausts the regex engine's stack on a long text. A fence may open right after a list marker, which then stays outside the part.
    private const string OPENING = '/^(?: {0,3}(?:[-*+]|\d{1,9}[.)]) {1,4}\K)?(?<fence> {0,3}(?:`{3,}(?=[^`\n]*$)|~{3,}))|(?<code>`+)|(?<comment><!--)/m';

    // A code span stays inside its paragraph, which ends at a blank line, a heading line or a fence line.
    private const string PARAGRAPH_END = '/\n(?:[ \t]*(?:\n|\z)| {0,3}#{1,6}(?:[ \t]|$)| {0,3}(?:`{3}|~{3}))/m';

    private const string COMMENT_END = '-->';

    private const string LIST_ITEM = '/^( *)([-*+]|\d{1,9}[.)])( +)\S/';

    private const string THEMATIC_BREAK = '/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/';

    private const string INDENT_AND_MARKER = '/^[ \t]*(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)?/';

    private const int TAB_STOP = 4;

    public static function visible(string $markdown): string
    {
        return self::read($markdown, static fn (string $fence): string => $fence);
    }

    public static function visibleProse(string $markdown): string
    {
        return self::read($markdown, self::lineBreaksOf(...));
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

    /**
     * @param callable(string): string $fenced
     */
    private static function read(string $markdown, callable $fenced): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);
        $read = '';
        $offset = 0;

        while (null !== ($match = self::firstMatch(self::OPENING, $text, $offset))) {
            $start = $match[0][1];
            $part = match (true) {
                '' !== ($match['fence'][0] ?? '') => self::fenceAt($text, $start),
                '' !== ($match['code'][0] ?? '') => self::codeSpanOrLiteralAt($text, $start, $offset),
                default => self::commentAt($text, $start),
            };
            $read .= substr($text, $offset, $start - $offset).match (true) {
                '' !== ($match['fence'][0] ?? '') && $start > 0 && "\n" !== $text[$start - 1] => self::afterTheOpeningLine($part, $fenced),
                '' !== ($match['fence'][0] ?? '') => $fenced($part),
                '' !== ($match['code'][0] ?? '') => $part,
                default => self::lineBreaksOf($part),
            };
            $offset = $start + strlen($part);
        }

        return $read.substr($text, $offset);
    }

    // An unreadable text must stop the check, because reading it as empty would pass a task's Evidence list unseen.
    /**
     * @return array<array{string, int}>|null
     */
    private static function firstMatch(string $pattern, string $text, int $offset): ?array
    {
        return match (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $offset)) {
            1 => $match,
            0 => null,
            false => throw new RuntimeException('the Markdown text cannot be read: '.preg_last_error_msg()),
        };
    }

    // A fence closes only with a run of its own character at least as long.
    private static function fenceAt(string $text, int $start): string
    {
        $indent = strspn($text, ' ', $start);
        $character = $text[$start + $indent];
        $closing = '/\n {0,3}'.preg_quote($character, '/').'{'.strspn($text, $character, $start + $indent).',}[ \t]*(?=\n|\z)/';
        $close = self::firstMatch($closing, $text, $start);
        $end = null === $close ? strlen($text) : $close[0][1] + strlen($close[0][0]);

        return self::untilItsListItemEnds(substr($text, 0, $start), substr($text, $start, $end - $start));
    }

    // A code span closes only with a backtick run of the same length in the same paragraph; a run without one, and a backtick escaped with a backslash, is a literal backtick (https://spec.commonmark.org/0.31.2/#backslash-escapes).
    private static function codeSpanOrLiteralAt(string $text, int $start, int $offset): string
    {
        $escaped = self::isEscaped($text, $start, $offset) ? 1 : 0;
        $length = strspn($text, '`', $start) - $escaped;
        $close = 0 === $length ? null : self::closingRunEnd($text, $start + $escaped + $length, $length);

        return substr($text, $start, ($close ?? $start + $escaped + $length) - $start);
    }

    // Backslashes before the part read so far may belong to a code span, where they escape nothing.
    private static function isEscaped(string $text, int $start, int $offset): bool
    {
        $before = substr($text, $offset, $start - $offset);

        return 1 === (strlen($before) - strlen(rtrim($before, '\\'))) % 2;
    }

    private static function closingRunEnd(string $text, int $from, int $length): ?int
    {
        $paragraphEnd = self::firstMatch(self::PARAGRAPH_END, $text, $from)[0][1] ?? strlen($text);
        $position = strpos($text, '`', $from);

        while (false !== $position && $position < $paragraphEnd) {
            $run = strspn($text, '`', $position);

            if ($run === $length) {
                return $position + $run;
            }

            $position = strpos($text, '`', $position + $run);
        }

        return null;
    }

    private static function commentAt(string $text, int $start): string
    {
        $end = strpos($text, self::COMMENT_END, $start + strlen('<!--'));

        return substr($text, $start, false === $end ? null : $end + strlen(self::COMMENT_END) - $start);
    }

    // A fence opened inside a list item ends with that item, closed or not, so the first line indented less than the item's content is outside it and is read again. A fence opened right after a list marker belongs to that marker's item.
    private static function untilItsListItemEnds(string $before, string $fence): string
    {
        $lines = explode("\n", $before);
        $marker = array_last($lines);
        $column = '' === trim($marker) ? self::columnOfTheItemAround($before, strspn($fence, ' ')) : strlen($marker);

        if (null === $column) {
            return $fence;
        }

        return 1 === preg_match('/\n(?= {0,'.($column - 1).'}\S)/', $fence, $match, PREG_OFFSET_CAPTURE) ? substr($fence, 0, $match[0][1]) : $fence;
    }

    // The walk goes up from the fence, because the item a line belongs to is the nearest list item above whose content column the line reaches, and a less indented line in between that is no such item has ended every deeper item.
    private static function columnOfTheItemAround(string $before, int $indent): ?int
    {
        foreach (array_map(self::withSpacesForTabs(...), array_reverse(explode("\n", $before))) as $line) {
            if ('' === trim($line) || strspn($line, ' ') > $indent) {
                continue;
            }

            $column = self::listItemColumnOf($line);

            if (null !== $column && $column <= $indent) {
                return $column;
            }

            $indent = strspn($line, ' ');
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

    // The opening line of a fence after a list marker stays as it is, because the marker still starts an item.
    /**
     * @param callable(string): string $fenced
     */
    private static function afterTheOpeningLine(string $fence, callable $fenced): string
    {
        $lineEnd = strcspn($fence, "\n");

        return substr($fence, 0, $lineEnd).$fenced(substr($fence, $lineEnd));
    }
}
