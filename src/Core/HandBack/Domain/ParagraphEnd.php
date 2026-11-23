<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

// A code span cannot run past its paragraph, and a paragraph ends not only at a blank line: a heading, a setext underline, a fence, a thematic break, a list item and an HTML comment each start a block of their own (https://spec.commonmark.org/0.31.2/#paragraphs, https://spec.commonmark.org/0.31.2/#html-blocks). Such a line counts at any indent, because inside a list item it is indented to the item's content, and ending a span too early only hides more.
final readonly class ParagraphEnd
{
    private const string BLANK = " \t";

    private const string COMMENT_START = '<!--';

    private const int LONGEST_HEADING_RUN = 6;

    private const int LONGEST_ITEM_NUMBER = 9;

    private const int SHORTEST_FENCE_RUN = 3;

    public static function after(string $text, int $from): int
    {
        $lineEnd = MarkdownText::lineEndAt($text, $from);

        // An ATX heading is one line, so a span opened in it ends with that line.
        if (self::isAtxHeading($text, self::afterTheListMarker($text, MarkdownText::lineStartAt($text, $from)))) {
            return $lineEnd;
        }

        while ($lineEnd < strlen($text) && !self::startsABlock($text, $lineEnd + 1)) {
            $lineEnd = MarkdownText::lineEndAt($text, $lineEnd + 1);
        }

        return $lineEnd;
    }

    private static function afterTheListMarker(string $text, int $line): int
    {
        $at = $line + strspn($text, self::BLANK, $line);
        $at += self::markerLengthAt($text, $at);

        return $at + strspn($text, self::BLANK, $at);
    }

    private static function isAtxHeading(string $text, int $at): bool
    {
        $run = strspn($text, '#', $at);

        return $run >= 1 && $run <= self::LONGEST_HEADING_RUN && self::isBlankOrLineEnd($text, $at + $run);
    }

    private static function startsABlock(string $text, int $line): bool
    {
        $at = $line + strspn($text, self::BLANK, $line);

        return match ($text[$at] ?? "\n") {
            "\n" => true,
            '#' => self::isAtxHeading($text, $at),
            '`', '~' => self::opensAFence($text, $at),
            '=' => self::isRule($text, $at, 1),
            '-' => self::isRule($text, $at, 1) || self::markerLengthAt($text, $at) > 0,
            '*' => self::isRule($text, $at, 3) || self::markerLengthAt($text, $at) > 0,
            '_' => self::isRule($text, $at, 3),
            '<' => 0 === substr_compare($text, self::COMMENT_START, $at, strlen(self::COMMENT_START)),
            default => self::markerLengthAt($text, $at) > 0,
        };
    }

    private static function markerLengthAt(string $text, int $at): int
    {
        $digits = strspn($text, '0123456789', $at);
        $marker = $text[$at + $digits] ?? '';
        $length = match (true) {
            0 === $digits && in_array($marker, ['-', '*', '+'], true) => 1,
            $digits >= 1 && $digits <= self::LONGEST_ITEM_NUMBER && in_array($marker, ['.', ')'], true) => $digits + 1,
            default => 0,
        };

        return $length > 0 && self::isBlankOrLineEnd($text, $at + $length) ? $length : 0;
    }

    private static function isBlankOrLineEnd(string $text, int $at): bool
    {
        return in_array($text[$at] ?? "\n", [' ', "\t", "\n"], true);
    }

    // A backtick fence has no backtick after its run, or the line would be inline code.
    private static function opensAFence(string $text, int $at): bool
    {
        $run = strspn($text, $text[$at], $at);
        $rest = $at + $run + strcspn($text, "`\n", $at + $run);

        return $run >= self::SHORTEST_FENCE_RUN && ('~' === $text[$at] || '`' !== ($text[$rest] ?? "\n"));
    }

    // One walk serves both, because a setext underline is a line of `=` or `-` only and a thematic break is three or more of its character with blanks between them.
    private static function isRule(string $text, int $at, int $shortest): bool
    {
        $character = $text[$at];
        $count = 0;

        do {
            $run = strspn($text, $character, $at);
            $count += $run;
            $at += $run + strspn($text, self::BLANK, $at + $run);
        } while ($run > 0);

        return $count >= $shortest && "\n" === ($text[$at] ?? "\n");
    }
}
