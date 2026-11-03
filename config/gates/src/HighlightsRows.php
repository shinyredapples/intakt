<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class HighlightsRows
{
    private const string HEADING = '/^ {0,3}(#{1,6})\s+(.*?)\s*#*\s*$/';

    // CommonMark lets an HTML comment open a block indented up to three spaces; it runs to the line holding -->.
    private const string COMMENT_OPENER = '/^ {0,3}<!--/';

    private const string CELL_BORDER = '/(?<!\\\\)\|/';

    private const string OUTER_PIPES = '/^\||(?<!\\\\)\|$/';

    public function __construct(
        private FencedCodeBlocks $codeBlocks,
    ) {
    }

    /**
     * @return list<HighlightsRow>
     */
    public function of(string $contents): array
    {
        $lines = explode("\n", $contents)
            |> $this->codeBlocks->blanked(...)
            |> $this->uncommented(...);
        $rows = [];
        $headings = [];
        $columns = null;

        foreach ($lines as $index => $line) {
            $headings = $this->headingsAfter($line, $headings);
            $cells = $this->cellsOf($line);

            if (null === $cells || null === $columns) {
                $columns = $this->columnsOf($cells, $lines[$index + 1] ?? '');

                continue;
            }

            if (!$this->isSeparator($cells)) {
                $rows[] = new HighlightsRow($index + 1, array_values($headings), ...$this->valuesOf($cells, $columns));
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function uncommented(array $lines): array
    {
        $inComment = false;

        foreach ($lines as $index => $line) {
            $inComment = $inComment || 1 === preg_match(self::COMMENT_OPENER, $line);

            if ($inComment) {
                $lines[$index] = '';
                $inComment = !str_contains($line, '-->');
            }
        }

        return $lines;
    }

    /**
     * @param array<int, string> $headings
     *
     * @return array<int, string>
     */
    private function headingsAfter(string $line, array $headings): array
    {
        if (1 !== preg_match(self::HEADING, $line, $heading)) {
            return $headings;
        }

        // A heading ends every section of its level or deeper, so a subheading keeps the section that holds it.
        $level = strlen($heading[1]);
        $kept = array_filter($headings, static fn (int $outer): bool => $outer < $level, ARRAY_FILTER_USE_KEY);
        $kept[$level] = $heading[2];

        return $kept;
    }

    /**
     * @param list<string>|null $cells
     *
     * @return array{status: int|false, where: int|false}|null
     */
    private function columnsOf(?array $cells, string $next): ?array
    {
        $separator = $this->cellsOf($next);

        // Only a separator row makes a header, because outer pipes are optional and prose may hold a pipe.
        if (null === $cells || null === $separator || !$this->isSeparator($separator)) {
            return null;
        }

        return ['status' => array_search('Status', $cells, true), 'where' => array_search('Where', $cells, true)];
    }

    /**
     * @param list<string>                               $cells
     * @param array{status: int|false, where: int|false} $columns
     *
     * @return array{status: ?string, where: ?string}
     */
    private function valuesOf(array $cells, array $columns): array
    {
        return [
            'status' => false === $columns['status'] ? null : ($cells[$columns['status']] ?? ''),
            'where' => false === $columns['where'] ? null : ($cells[$columns['where']] ?? ''),
        ];
    }

    /**
     * @param list<string> $cells
     */
    private function isSeparator(array $cells): bool
    {
        return [] === array_filter($cells, static fn (string $cell): bool => 1 !== preg_match('/^:?-+:?$/', $cell));
    }

    /**
     * @return list<string>|null
     */
    private function cellsOf(string $line): ?array
    {
        $line = trim($line);

        if (1 !== preg_match(self::CELL_BORDER, $line)) {
            return null;
        }

        $cells = preg_split(self::CELL_BORDER, (string) preg_replace(self::OUTER_PIPES, '', $line));

        // A table decodes an escaped pipe before inline parsing, also inside a code span (GFM), so `a\|b.md` names a|b.md.
        return false === $cells ? null : array_map(static fn (string $cell): string => str_replace('\|', '|', trim($cell)), $cells);
    }
}
