<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class HighlightsRows
{
    private const string HEADING = '/^ {0,3}(#{1,6})\s+(.*?)\s*#*\s*$/';

    private const string CELL_BORDER = '/(?<!\\\\)\|/';

    private const string OUTER_PIPES = '/^\||(?<!\\\\)\|$/';

    public function __construct(
        private MarkdownBlocks $blocks,
    ) {
    }

    /**
     * @return list<HighlightsRow>
     */
    public function of(string $contents): array
    {
        $lines = explode("\n", $contents);
        $blocks = $this->blocks->of($lines);
        $rows = [];
        $headings = [];
        $columns = null;

        foreach ($lines as $index => $line) {
            $block = $blocks[$index];
            $headings = $this->headingsAfter($line, $block, $headings);
            $cells = $this->cellsIn($block, $line);

            // A block boundary ends the table around it, so a header on the last line of a block cannot pair with a separator after it.
            if (null === $cells || null === $columns || $block !== ($blocks[$index - 1] ?? MarkdownBlock::Text)) {
                $columns = $this->columnsOf($cells, $lines[$index + 1] ?? '');

                continue;
            }

            if (!$this->isSeparator($cells)) {
                $rows[] = new HighlightsRow($index + 1, array_values($headings), $block, ...$this->valuesOf($cells, $columns));
            }
        }

        return $rows;
    }

    /**
     * @return list<string>|null
     */
    private function cellsIn(MarkdownBlock $block, string $line): ?array
    {
        // A table in a fenced block is an example, so its lines hold no cells and end any table around the block.
        return MarkdownBlock::Fence === $block ? null : $this->cellsOf($line);
    }

    /**
     * @param array<int, string> $headings
     *
     * @return array<int, string>
     */
    private function headingsAfter(string $line, MarkdownBlock $block, array $headings): array
    {
        // A commented-out heading is not rendered, so it opens no section for the rows after the comment.
        if (MarkdownBlock::Text !== $block || 1 !== preg_match(self::HEADING, $line, $heading)) {
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
