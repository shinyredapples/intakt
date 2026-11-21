<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

// The cells of the one pipe table in a section, so a second table or a row of another shape never stands in for it.
final readonly class MarkdownTable
{
    private const string LINE = '/^ {0,3}\|/';
    private const string ROW = '/^\|(.*)(?<!\\\)\|$/';
    private const string CELL_BOUNDARY = '/(?<!\\\)\|/';
    private const string DELIMITER_CELL = '/^:?-+:?$/';

    // The header row first, then the delimiter row, then the body rows; null unless the lines hold exactly one such table.
    /**
     * @param list<string> $lines
     *
     * @return list<list<string>>|null
     */
    public static function only(array $lines): ?array
    {
        $tables = self::tables($lines);
        if (1 !== count($tables)) {
            return null;
        }

        $rows = array_map(self::cells(...), $tables[0]);
        if (count($rows) < 3 || in_array([], $rows, true) || 1 !== count(array_unique(array_map(count(...), $rows)))) {
            return null;
        }

        return self::isDelimiter($rows[1]) ? $rows : null;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<non-empty-list<string>>
     */
    private static function tables(array $lines): array
    {
        $tables = [];
        $last = null;
        foreach (array_filter($lines, static fn (string $line): bool => 1 === preg_match(self::LINE, $line)) as $index => $line) {
            $tables[$index - 1 === $last ? count($tables) - 1 : count($tables)][] = $line;
            $last = $index;
        }

        return array_values($tables);
    }

    /**
     * @return list<string>
     */
    private static function cells(string $line): array
    {
        $cells = 1 === preg_match(self::ROW, trim($line), $row) ? preg_split(self::CELL_BOUNDARY, $row[1]) : false;

        return false === $cells ? [] : array_map(trim(...), $cells);
    }

    /**
     * @param list<string> $row
     */
    private static function isDelimiter(array $row): bool
    {
        return [] === array_filter($row, static fn (string $cell): bool => 1 !== preg_match(self::DELIMITER_CELL, $cell));
    }
}
