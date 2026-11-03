<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class HighlightsRows
{
    private const string HEADING = '/^#{1,6}\s+(.*?)\s*#*\s*$/';

    // Matches CommonMark, because ``a`b`` is one span and an escaped backtick opens none.
    private const string BACKTICKED = '/\\\\.(*SKIP)(*FAIL)|(`+)(?!`)(?:(.+?)(?<!`)\1(?!`)|(*SKIP)(*FAIL))/';

    // One space on each side is padding, so a span can start or end with a backtick (CommonMark).
    private const string PADDED_SPAN = '/^ (.*[^ ].*) $/';

    private const string LEADING_DOT_SLASH = '#^(?:\./)+#';

    private const string PATH_LIKE = '#^(?:[^*<>{}]+/[^*<>{}]*|[^*<>{}/]*\.\w+)$#';

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
        $lines = $this->codeBlocks->blanked(explode("\n", $contents));
        $rows = [];
        $section = '';
        $columns = null;

        foreach ($lines as $index => $line) {
            $section = $this->headingOf($line) ?? $section;
            $cells = $this->cellsOf($line);

            if (null === $cells || null === $columns) {
                $columns = $this->columnsOf($cells, $lines[$index + 1] ?? '');

                continue;
            }

            if (!$this->isSeparator($cells)) {
                $rows[] = new HighlightsRow($index + 1, $section, ...$this->valuesOf($cells, $columns));
            }
        }

        return $rows;
    }

    private function headingOf(string $line): ?string
    {
        return 1 === preg_match(self::HEADING, $line, $heading) ? $heading[1] : null;
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
     * @return array{status: ?string, paths: list<string>}
     */
    private function valuesOf(array $cells, array $columns): array
    {
        return [
            'status' => false === $columns['status'] ? null : ($cells[$columns['status']] ?? ''),
            'paths' => false === $columns['where'] ? [] : $this->pathsIn($cells[$columns['where']] ?? ''),
        ];
    }

    /**
     * @return list<string>
     */
    private function pathsIn(string $where): array
    {
        preg_match_all(self::BACKTICKED, $where, $matches);
        $paths = [];

        foreach ($matches[2] ?? [] as $span) {
            $path = preg_replace(self::PADDED_SPAN, '$1', $span) ?? $span;

            if (1 === preg_match(self::PATH_LIKE, $path)) {
                $paths[] = rtrim(preg_replace(self::LEADING_DOT_SLASH, '', $path) ?? $path, '/');
            }
        }

        return $paths;
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
