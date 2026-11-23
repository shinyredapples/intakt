<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

// The cells of the one pipe table in a section, so a second table or a row of another shape never stands in for it.
final readonly class MarkdownTable
{
    // Up to three spaces only, because Markdown reads a line indented four spaces as code, whose pipes never start a table.
    private const string TABLE_LINE = '/^ {0,3}\S/';
    private const string CELL_BOUNDARY = '/(?<!\\\)\|/';
    private const string DELIMITER_CELL = '/^:?-+:?$/';

    // Every header and delimiter pair counts, outer pipes or not, so a second table in either syntax refuses the section instead of hiding behind the first.
    /**
     * @param list<string> $lines
     *
     * @return list<list<string>>|null
     */
    public static function only(array $lines): ?array
    {
        $starts = array_keys(array_filter(array_keys($lines), static fn (int $index): bool => self::startsTable($lines, $index)));
        if (1 !== count($starts)) {
            return null;
        }

        $rows = self::rowsFrom($lines, $starts[0]);
        if (count($rows) < 3 || 1 !== count(array_unique(array_map(count(...), $rows)))) {
            return null;
        }

        return $rows;
    }

    /**
     * @param list<string> $lines
     */
    private static function startsTable(array $lines, int $index): bool
    {
        $delimiter = $lines[$index + 1] ?? '';
        if (!self::hasCells($lines[$index]) || !self::hasCells($delimiter)) {
            return false;
        }

        $cells = self::cells($delimiter);

        return count($cells) === count(self::cells($lines[$index]))
            && [] === array_filter($cells, static fn (string $cell): bool => 1 !== preg_match(self::DELIMITER_CELL, $cell));
    }

    private static function hasCells(string $line): bool
    {
        return 1 === preg_match(self::TABLE_LINE, $line) && 1 === preg_match(self::CELL_BOUNDARY, $line);
    }

    // A table runs until the first blank line, so a following line without pipes is a row of another width and refuses the table.
    /**
     * @param list<string> $lines
     *
     * @return list<list<string>>
     */
    private static function rowsFrom(array $lines, int $start): array
    {
        $rows = [];
        foreach (array_slice($lines, $start) as $line) {
            if ('' === trim($line)) {
                break;
            }

            $rows[] = self::cells($line);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function cells(string $line): array
    {
        $row = preg_replace(['/^\|/', '/(?<!\\\)\|$/'], '', trim($line));
        $cells = null === $row ? false : preg_split(self::CELL_BOUNDARY, $row);

        return false === $cells ? [] : array_map(trim(...), $cells);
    }
}
