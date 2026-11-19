<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

// Item n is the n-th top-level list item under the task's first Evidence heading, because the builder numbers them E1, E2, ... in that order whether or not the task writes the numbers.
final readonly class EvidenceItems
{
    private const string ATX_HEADING = '/^ {0,3}#{1,6}(?:[ \t]|$)/';

    private const string SETEXT_UNDERLINE = '/^ {0,3}(?:=+|-+)[ \t]*$/';

    private const string EVIDENCE_HEADING_TEXT = '/^ {0,3}(?:#{1,6}[ \t]+)?(?:\*\*)?Evidence\b/i';

    private const string EVIDENCE_LABEL = '/^ {0,3}(?:\*\*)?Evidence(?:\*\*)?[ \t]*:?[ \t]*(?:\*\*)?[ \t]*$/i';

    private const string LABEL = '/^(?:\*\*)?[A-Z][A-Za-z \t]*:(?:\*\*)?[ \t]*$/';

    private const string LIST_ITEM = '/^( *)([-*+]|\d{1,9}[.)])( +)\S/';

    private const string NUMBERED_LINE = '/^( *)E\d+[.:)]/';

    private const string THEMATIC_BREAK = '/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/';

    public static function countIn(string $task): int
    {
        $lines = array_map(self::withSpacesForTabs(...), explode("\n", MarkdownText::visibleProse($task)));

        foreach (array_keys($lines) as $index) {
            $heading = self::headingLines($lines, $index);

            if ($heading > 0 && 1 === preg_match(self::EVIDENCE_HEADING_TEXT, $lines[$index])) {
                return self::topLevelItems(self::untilHeading(array_slice($lines, $index + $heading)));
            }

            if (1 === preg_match(self::EVIDENCE_LABEL, $lines[$index])) {
                return self::topLevelItems(self::untilTheLabelListEnds(array_slice($lines, $index + 1)));
            }
        }

        return 0;
    }

    private static function withSpacesForTabs(string $line): string
    {
        return preg_replace_callback('/^[ \t]+/', static fn (array $match): string => str_replace("\t", '    ', $match[0]), $line) ?? $line;
    }

    // A section under a heading runs to the next heading, as Markdown renders it, so an introduction line or a paragraph inside it ends nothing.
    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function untilHeading(array $lines): array
    {
        $end = array_find_key($lines, static fn (string $line, int $index): bool => self::headingLines($lines, $index) > 0);

        return null === $end ? $lines : array_slice($lines, 0, $end);
    }

    // A plain `Evidence:` label has no end of its own, so its list ends where the text after the first item starts.
    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function untilTheLabelListEnds(array $lines): array
    {
        $section = self::untilHeading($lines);
        $first = array_find_key($section, static fn (string $line): bool => null !== self::contentColumnOf($line));

        if (null === $first) {
            return $section;
        }

        $end = array_find_key($section, static fn (string $line, int $index): bool => $index > $first && self::endsTheLabelList($section, $index));

        return null === $end ? $section : array_slice($section, 0, $end);
    }

    // A lower-case line ending in a colon right after an item is kept, because it is the item wrapped onto a new line, not a label.
    /**
     * @param list<string> $section
     */
    private static function endsTheLabelList(array $section, int $index): bool
    {
        $line = $section[$index];

        if (1 === preg_match(self::LABEL, $line)) {
            return true;
        }

        return '' === trim($section[$index - 1]) && 1 === preg_match('/^\S/', $line) && null === self::contentColumnOf($line);
    }

    /**
     * @param list<string> $section
     */
    private static function topLevelItems(array $section): int
    {
        $count = 0;
        $column = null;

        foreach ($section as $index => $line) {
            $column = self::endsTheList($section, $index, $column) ? null : $column;

            if (self::isTopLevelItem($line, $column)) {
                ++$count;
                $column = self::contentColumnOf($line);
            }
        }

        return $count;
    }

    // An item indented to the content of the item above is nested in it (https://spec.commonmark.org/0.31.2/#list-items).
    private static function isTopLevelItem(string $line, ?int $column): bool
    {
        return null !== self::contentColumnOf($line) && self::indentOf($line) <= 3 && (null === $column || self::indentOf($line) < $column);
    }

    // A paragraph after a blank line, indented less than the item's content, is outside the list, because Markdown no longer reads it as part of the item.
    /**
     * @param list<string> $section
     */
    private static function endsTheList(array $section, int $index, ?int $column): bool
    {
        $line = $section[$index];

        return $index > 0 && '' === trim($section[$index - 1]) && '' !== trim($line)
            && null === self::contentColumnOf($line) && self::indentOf($line) < ($column ?? 0);
    }

    /**
     * @param list<string> $lines
     *
     * @return int<0, 2>
     */
    private static function headingLines(array $lines, int $index): int
    {
        $line = $lines[$index];

        if (1 === preg_match(self::ATX_HEADING, $line)) {
            return 1;
        }

        $isParagraphLine = '' !== trim($line) && self::indentOf($line) <= 3 && null === self::contentColumnOf($line);

        return $isParagraphLine && 1 === preg_match(self::SETEXT_UNDERLINE, $lines[$index + 1] ?? '') ? 2 : 0;
    }

    private static function contentColumnOf(string $line): ?int
    {
        if (1 === preg_match(self::THEMATIC_BREAK, $line)) {
            return null;
        }

        if (1 === preg_match(self::LIST_ITEM, $line, $match)) {
            // Five or more spaces after the marker start indented code inside the item, so its content begins one space after the marker.
            return strlen($match[1]) + strlen($match[2]) + (strlen($match[3]) > 4 ? 1 : strlen($match[3]));
        }

        return 1 === preg_match(self::NUMBERED_LINE, $line, $match) ? strlen($match[1]) + 2 : null;
    }

    private static function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }
}
