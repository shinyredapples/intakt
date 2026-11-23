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

    private const string NUMBERED_LINE = '/^( *)E\d+[.:)]/';

    public static function countIn(string $task): int
    {
        $lines = HiddenBlockOpeners::proseLinesOf($task);
        $column = null;

        foreach (array_keys($lines) as $index) {
            // A heading or label indented into an open item's content is an example inside the task, so it must not start the section.
            $column = self::endsTheList($lines, $index, $column) ? null : $column;
            $section = self::sectionStartingAt($lines, $index, $column);

            if (null !== $section) {
                return self::topLevelItems($section);
            }

            $column = self::isTopLevelItem($lines, $index, $column) ? self::contentColumnOf($lines[$index]) : $column;
        }

        return 0;
    }

    // A paragraph after a blank line, or an ATX heading or a thematic break with or without one, indented less than the item's content, is outside the list, because Markdown no longer reads it as part of the item.
    /**
     * @param list<string> $section
     */
    private static function endsTheList(array $section, int $index, ?int $column): bool
    {
        $line = $section[$index];

        if (null === $column || self::indentOf($line) >= $column) {
            return false;
        }

        return 1 === preg_match(self::ATX_HEADING, $line)
            || MarkdownText::isThematicBreak($line)
            || ($index > 0 && '' === trim($section[$index - 1]) && '' !== trim($line) && null === self::contentColumnOf($line));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>|null
     */
    private static function sectionStartingAt(array $lines, int $index, ?int $column): ?array
    {
        if (null !== $column && self::indentOf($lines[$index]) >= $column) {
            return null;
        }

        $heading = self::headingLines($lines, $index);

        if ($heading > 0 && 1 === preg_match(self::EVIDENCE_HEADING_TEXT, $lines[$index])) {
            return array_slice($lines, $index + $heading);
        }

        return 1 === preg_match(self::EVIDENCE_LABEL, $lines[$index]) ? self::untilTheLabelListEnds(array_slice($lines, $index + 1)) : null;
    }

    // The end of the section is decided while walking the items, because only there the content column of the open item is known, and a heading indented to it is part of that item.
    /**
     * @param list<string> $lines
     */
    private static function topLevelItems(array $lines): int
    {
        $count = 0;
        $column = null;

        foreach ($lines as $index => $line) {
            $column = self::endsTheList($lines, $index, $column) ? null : $column;

            if (self::endsTheSection($lines, $index, $column)) {
                break;
            }

            if (self::isTopLevelItem($lines, $index, $column)) {
                ++$count;
                $column = self::contentColumnOf($line);
            }
        }

        return $count;
    }

    // An item indented to the content of the item above is nested in it, and outside a list an item that cannot interrupt a paragraph (an empty marker line, an ordered item not numbered 1) continues the paragraph line above it (https://spec.commonmark.org/0.31.2/#list-items).
    /**
     * @param list<string> $lines
     */
    private static function isTopLevelItem(array $lines, int $index, ?int $column): bool
    {
        $line = $lines[$index];

        if (null === self::contentColumnOf($line) || self::indentOf($line) > 3 || (null !== $column && self::indentOf($line) >= $column)) {
            return false;
        }

        return null !== $column || !MarkdownText::continuesAParagraph($lines[$index - 1] ?? '', $line);
    }

    private static function contentColumnOf(string $line): ?int
    {
        return MarkdownText::listItemColumnOf($line)
            ?? (1 === preg_match(self::NUMBERED_LINE, $line, $match) ? strlen($match[1]) + 2 : null);
    }

    private static function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
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

        // A thematic break is no paragraph line, so a `-` below it is an empty item, not a Setext underline.
        $isParagraphLine = '' !== trim($line) && self::indentOf($line) <= 3 && null === self::contentColumnOf($line) && !MarkdownText::isThematicBreak($line);

        return $isParagraphLine && 1 === preg_match(self::SETEXT_UNDERLINE, $lines[$index + 1] ?? '') ? 2 : 0;
    }

    // A plain `Evidence:` label has no end of its own, so its list also ends where the text after the first item starts. Such a line has no indent, so it is never inside an item and can be cut before the items are walked. The first item is a top-level one, because a marker in indented code starts no list.
    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function untilTheLabelListEnds(array $lines): array
    {
        $first = array_find_key($lines, static fn (string $line, int $index): bool => self::isTopLevelItem($lines, $index, null));

        if (null === $first) {
            return $lines;
        }

        $end = array_find_key($lines, static fn (string $line, int $index): bool => $index > $first && self::endsTheLabelList($lines, $index));

        return null === $end ? $lines : array_slice($lines, 0, $end);
    }

    // A section runs to the next heading outside the open item, as Markdown renders it, so an introduction line or a paragraph inside it ends nothing.
    /**
     * @param list<string> $lines
     */
    private static function endsTheSection(array $lines, int $index, ?int $column): bool
    {
        return self::indentOf($lines[$index]) < ($column ?? PHP_INT_MAX) && self::headingLines($lines, $index) > 0;
    }

    // A lower-case line ending in a colon right after an item is kept, because it is the item wrapped onto a new line, not a label. A thematic break is no text, and it is also what stands in for a hidden comment or fence, so a note hidden between two items does not cut the list.
    /**
     * @param list<string> $section
     */
    private static function endsTheLabelList(array $section, int $index): bool
    {
        $line = $section[$index];

        if (1 === preg_match(self::LABEL, $line)) {
            return true;
        }

        return '' === trim($section[$index - 1]) && 1 === preg_match('/^\S/', $line) && null === self::contentColumnOf($line) && !MarkdownText::isThematicBreak($line);
    }
}
