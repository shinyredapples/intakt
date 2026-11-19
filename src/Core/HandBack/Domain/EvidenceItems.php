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
        $lines = self::unfencedLines($task);

        foreach (array_keys($lines) as $index) {
            $heading = self::headingLines($lines, $index);

            if ($heading > 0 && 1 === preg_match(self::EVIDENCE_HEADING_TEXT, $lines[$index])) {
                return self::countSection(array_slice($lines, $index + $heading), underLabel: false);
            }

            if (1 === preg_match(self::EVIDENCE_LABEL, $lines[$index])) {
                return self::countSection(array_slice($lines, $index + 1), underLabel: true);
            }
        }

        return 0;
    }

    /**
     * @return list<string>
     */
    private static function unfencedLines(string $task): array
    {
        return array_map(
            static fn (array $line): string => $line[1] ? '' : self::withSpacesForTabs($line[0]),
            MarkdownText::visibleLines($task),
        );
    }

    private static function withSpacesForTabs(string $line): string
    {
        return preg_replace_callback('/^[ \t]+/', static fn (array $match): string => str_replace("\t", '    ', $match[0]), $line) ?? $line;
    }

    /**
     * A heading section runs to the next heading, as Markdown renders it; a plain `Evidence:` label has no such end, so its list ends where the text after it starts.
     *
     * @param list<string> $lines
     */
    private static function countSection(array $lines, bool $underLabel): int
    {
        $count = 0;
        $contentColumn = null;
        $previousBlank = false;

        foreach (array_keys($lines) as $index) {
            $line = $lines[$index];

            if (self::headingLines($lines, $index) > 0 || ($underLabel && $count > 0 && self::endsTheLabelList($line, $previousBlank))) {
                break;
            }

            $itemColumn = self::contentColumnOf($line);

            if (null !== $itemColumn && self::indentOf($line) <= 3 && (null === $contentColumn || self::indentOf($line) < $contentColumn)) {
                ++$count;
                $contentColumn = $itemColumn;
            } elseif (null === $itemColumn && $previousBlank && '' !== trim($line) && self::indentOf($line) < ($contentColumn ?? 0)) {
                $contentColumn = null;
            }

            $previousBlank = '' === trim($line);
        }

        return $count;
    }

    // A lower-case line ending in a colon right after an item is the item wrapped onto a new line, not a label.
    private static function endsTheLabelList(string $line, bool $previousBlank): bool
    {
        if (1 === preg_match(self::LABEL, $line)) {
            return true;
        }

        return $previousBlank && 1 === preg_match('/^\S/', $line) && null === self::contentColumnOf($line);
    }

    /**
     * @param list<string> $lines
     *
     * @return int<0, 2> how many lines the heading at this line takes
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
