<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownDelimiterRow
{
    private const string CELL_BORDER = '/(?<!\\\\)\|/';

    private const array OUTER_PIPES = ['/^\s*\|/', '/(?<!\\\\)\|\s*$/'];

    private const string DELIMITER_CELL = '/^:?-+:?$/';

    public function delimits(string $header, string $delimiter): bool
    {
        $cells = $this->cells($delimiter);

        // A row with a missing column or a cell without a hyphen is no delimiter in GFM, so the prose below it must stay measured.
        return count($cells) === count($this->cells($header))
            && [] === array_filter($cells, static fn (string $cell): bool => 0 === preg_match(self::DELIMITER_CELL, $cell));
    }

    /**
     * @return list<string>
     */
    private function cells(string $row): array
    {
        $cells = preg_split(self::CELL_BORDER, preg_replace(self::OUTER_PIPES, '', $row) ?? $row);

        return false === $cells ? [] : array_map(trim(...), $cells);
    }
}
