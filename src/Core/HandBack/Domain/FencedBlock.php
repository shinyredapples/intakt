<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

// The end of a fence is found by walking its lines with string functions, because a closing pattern that holds the length of the opening run is refused by the regex engine once that run is longer than its largest quantifier.
final readonly class FencedBlock
{
    private const string BLANK = " \t";

    private const int MOST_INDENT = 3;

    // No list item has its content left of this column (a one-character marker and one space), so a fence indented less is in no item.
    private const int NARROWEST_ITEM_COLUMN = 2;

    // Only a run of its own character at least as long closes a fence (https://spec.commonmark.org/0.31.2/#fenced-code-blocks). A fence opened inside a list item ends with that item, closed or not, so the first line indented less than the item's content is outside it and is read again.
    public static function at(string $text, int $start): string
    {
        $indent = strspn($text, ' ', $start);
        $character = $text[$start + $indent];
        $run = strspn($text, $character, $start + $indent);
        $column = self::columnOfItsListItem($text, $start, $indent);
        $lineEnd = MarkdownText::lineEndAt($text, $start);

        while ($lineEnd < strlen($text)) {
            $line = $lineEnd + 1;

            if (self::isLeftOf($column, $text, $line)) {
                break;
            }

            $lineEnd = MarkdownText::lineEndAt($text, $line);

            // A closing line is indented up to three columns from where its container's content starts, so inside a list item the item's content column is added.
            if (self::closingRunAt($text, $line, $character) >= $run && self::indentColumnsAt($text, $line) <= ($column ?? 0) + self::MOST_INDENT) {
                break;
            }
        }

        return substr($text, $start, $lineEnd - $start);
    }

    // A fence is indented up to three columns from where its container's content starts, so inside a list item it may sit further right, while four or more past that column make indented code.
    public static function opensAt(string $text, int $start): bool
    {
        $indent = strspn($text, ' ', $start);

        if ($indent <= self::MOST_INDENT) {
            return true;
        }

        $column = MarkdownText::lineStartAt($text, $start) === $start ? self::columnOfTheItemAbove($text, $start, $indent) : null;

        return null !== $column && $indent - $column <= self::MOST_INDENT;
    }

    private static function isLeftOf(?int $column, string $text, int $line): bool
    {
        $isBlank = "\n" === ($text[$line + strspn($text, self::BLANK, $line)] ?? "\n");

        return null !== $column && !$isBlank && self::indentColumnsAt($text, $line) < $column;
    }

    private static function closingRunAt(string $text, int $line, string $character): int
    {
        $start = $line + strspn($text, self::BLANK, $line);
        $run = strspn($text, $character, $start);
        $next = $text[$start + $run + strspn($text, self::BLANK, $start + $run)] ?? "\n";

        return "\n" === $next ? $run : 0;
    }

    // A tab in the indent runs to the next tab stop, because the content column it is compared with was measured that way on the list item's line.
    private static function indentColumnsAt(string $text, int $line): int
    {
        $columns = 0;

        for ($at = $line, $end = $line + strspn($text, self::BLANK, $line); $at < $end; ++$at) {
            $columns += "\t" === $text[$at] ? MarkdownText::TAB_STOP - $columns % MarkdownText::TAB_STOP : 1;
        }

        return $columns;
    }

    // A fence opened right after a list marker is the first content of that marker's item, so the item's content column is where the fence starts.
    private static function columnOfItsListItem(string $text, int $start, int $indent): ?int
    {
        $lineStart = MarkdownText::lineStartAt($text, $start);
        $marker = substr($text, $lineStart, $start - $lineStart);

        return '' === $marker
            ? self::columnOfTheItemAbove($text, $lineStart, $indent)
            : strlen(MarkdownText::withSpacesForTabs($marker));
    }

    // The walk goes up from the fence, because the item a line belongs to is the nearest list item above whose content column the line reaches, and a less indented line in between that is no such item has ended every deeper item.
    private static function columnOfTheItemAbove(string $text, int $lineStart, int $indent): ?int
    {
        while ($lineStart > 0 && $indent >= self::NARROWEST_ITEM_COLUMN) {
            $lineEnd = $lineStart - 1;
            $lineStart = MarkdownText::lineStartAt($text, $lineEnd);
            $line = MarkdownText::withSpacesForTabs(substr($text, $lineStart, $lineEnd - $lineStart));

            if ('' === trim($line) || strspn($line, ' ') > $indent) {
                continue;
            }

            $column = MarkdownText::listItemColumnOf($line) ?? PHP_INT_MAX;

            if ($column <= $indent) {
                return $column;
            }

            $indent = strspn($line, ' ');
        }

        return null;
    }
}
