<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

// The end of a fence is found by walking its lines with string functions, because a closing pattern that holds the length of the opening run is refused by the regex engine once that run is longer than its largest quantifier.
final readonly class FencedBlock
{
    private const string BLANK = " \t";

    private const int MOST_INDENT = 3;

    // Only a run of its own character at least as long closes a fence (https://spec.commonmark.org/0.31.2/#fenced-code-blocks). A fence opened inside a list item ends with that item, closed or not, so the first line indented less than the item's content is outside it and is read again.
    public static function at(string $text, int $start, ?OpenListItems $items = null): string
    {
        $indent = strspn($text, self::BLANK, $start);
        $character = $text[$start + $indent];
        $run = strspn($text, $character, $start + $indent);
        $column = self::columnOfItsListItem($text, $start, $items ?? OpenListItems::of($text));
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

    // A fence is indented up to three columns from where its container's content starts, so inside a list item it may sit further right, while four or more past that column make indented code. The indent is counted in columns, a tab to its tab stop, as the content column was; right after a list marker the item's content starts at the fence, so only up to three blanks may come before it.
    public static function opensAt(string $text, int $start, ?OpenListItems $items = null): bool
    {
        $lineStart = MarkdownText::lineStartAt($text, $start);

        // After a marker the fence opens only where the item's content starts: five or more spaces after the marker make the item's content start one space after it, so the run is indented code. A marker that continues the paragraph above it starts no item, so the run after it opens nothing either.
        if ($lineStart !== $start) {
            $upToTheRun = MarkdownText::withSpacesForTabs(substr($text, $lineStart, $start + strspn($text, self::BLANK, $start) - $lineStart));

            return strspn($text, self::BLANK, $start) <= self::MOST_INDENT
                && MarkdownText::listItemColumnOf($upToTheRun.'x') === strlen($upToTheRun)
                && ($items ?? OpenListItems::of($text))->readUpTo($lineStart)->letsAnItemStartOn(substr($text, $lineStart, MarkdownText::lineEndAt($text, $start) - $lineStart));
        }

        $indent = self::indentColumnsAt($text, $start);

        if ($indent <= self::MOST_INDENT) {
            return true;
        }

        $column = ($items ?? OpenListItems::of($text))->readUpTo($start)->columnFor($indent);

        return null !== $column && $indent - $column <= self::MOST_INDENT;
    }

    // A fence opened right after a list marker is the first content of that marker's item, so the item's content column is where the fence starts; a fence on a line of its own is in the open item whose content column its indent reaches.
    private static function columnOfItsListItem(string $text, int $start, OpenListItems $items): ?int
    {
        $lineStart = MarkdownText::lineStartAt($text, $start);

        return $lineStart === $start
            ? $items->readUpTo($start)->columnFor(self::indentColumnsAt($text, $start))
            : strlen(MarkdownText::withSpacesForTabs(substr($text, $lineStart, $start - $lineStart)));
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
}
