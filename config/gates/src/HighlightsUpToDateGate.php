<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Closure;

final readonly class HighlightsUpToDateGate implements Gate
{
    private const string FILE = 'docs/highlights.md';

    private const string UPSTREAM = 'upstream';

    private const string FOUND_IN_THE_BETA = 'Found in the beta';

    private const string HEADING = '/^#{1,6}\s+(.*?)\s*#*\s*$/';

    // Matches CommonMark, because ``a`b`` is one span and an escaped backtick opens none.
    private const string BACKTICKED = '/\\\\.(*SKIP)(*FAIL)|(`+)(?!`)(?:(.+?)(?<!`)\1(?!`)|(*SKIP)(*FAIL))/';

    // One space on each side is padding, so a span can start or end with a backtick (CommonMark).
    private const string PADDED_SPAN = '/^ (.*[^ ].*) $/';

    private const string LEADING_DOT_SLASH = '#^(?:\./)+#';

    private const string PATH_LIKE ='#^(?:[^*<>{}]+/[^*<>{}]*|[^*<>{}/]*\.\w+)$#';

    private const string CELL_BORDER = '/(?<!\\\\)\|/';

    private const string OUTER_PIPES = '/^\||(?<!\\\\)\|$/';

    public function __construct(
        private FencedCodeBlocks $codeBlocks,
    ) {
    }

    public function name(): string
    {
        return 'highlights-up-to-date';
    }

    public function run(Workspace $workspace): GateResult
    {
        $file = $workspace->root.'/'.self::FILE;
        $contents = is_file($file) ? file_get_contents($file) : '';

        if (false === $contents) {
            return GateResult::failedBecause($this->name(), 'cannot read '.self::FILE);
        }

        $tracked = $workspace->hasGit() ? $workspace->git(['ls-files', '-z', '--cached']) : null;

        if ($tracked instanceof ProcessResult && !$tracked->succeeded()) {
            return GateResult::failedBecause($this->name(), 'cannot list tracked files: '.$tracked->firstErrorLine());
        }

        $rows = $this->rowsOf($contents);
        $findings = [
            ...$this->reproductionsWithoutRow($this->reproductionsOn($workspace->root, $tracked), $rows),
            ...$this->plannedRowsAlreadyBuilt($this->existenceOn($workspace->root, $tracked), $rows),
        ];

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    /**
     * @param list<string>                                                                  $reproductions
     * @param list<array{line: int, section: string, status: ?string, paths: list<string>}> $rows
     *
     * @return list<string>
     */
    private function reproductionsWithoutRow(array $reproductions, array $rows): array
    {
        $named = [];

        foreach ($rows as $row) {
            if (self::FOUND_IN_THE_BETA === $row['section']) {
                $named = [...$named, ...$row['paths']];
            }
        }

        $findings = [];

        foreach ($reproductions as $reproduction) {
            $isNamed = [] !== array_filter($named, static fn (string $path): bool => $path === $reproduction || str_starts_with($path, $reproduction.'/'));

            if (!$isNamed) {
                $findings[] = self::FILE.': `'.$reproduction.'/` has no row in "'.self::FOUND_IN_THE_BETA.'" whose Where names it, add one';
            }
        }

        return $findings;
    }

    /**
     * @param Closure(string): bool                                                         $exists
     * @param list<array{line: int, section: string, status: ?string, paths: list<string>}> $rows
     *
     * @return list<string>
     */
    private function plannedRowsAlreadyBuilt(Closure $exists, array $rows): array
    {
        $findings = [];

        foreach ($rows as $row) {
            $missing = array_filter($row['paths'], static fn (string $path): bool => !$exists($path));

            if (HighlightStatus::Planned->value === $row['status'] && [] !== $row['paths'] && [] === $missing) {
                $findings[] = self::FILE.':'.$row['line'].': status "planned", but every path in Where exists ('.implode(', ', array_map(static fn (string $path): string => '`'.$path.'`', $row['paths'])).'), update its status to in progress or done';
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function reproductionsOn(string $root, ?ProcessResult $tracked): array
    {
        // Without git there is no branch, so the working tree stands in for it.
        if (!$tracked instanceof ProcessResult) {
            $folders = glob($root.'/'.self::UPSTREAM.'/*', GLOB_ONLYDIR | GLOB_NOSORT);

            return array_map(static fn (string $folder): string => self::UPSTREAM.'/'.basename($folder), false === $folders ? [] : $folders);
        }

        $reproductions = [];

        foreach (explode("\0", $tracked->output) as $file) {
            $parts = explode('/', $file, 3);

            // Only a file inside a folder makes the folder a reproduction, so upstream/README.md is none.
            if (3 === count($parts) && self::UPSTREAM === $parts[0]) {
                $reproductions[self::UPSTREAM.'/'.$parts[1]] = true;
            }
        }

        return array_keys($reproductions);
    }

    /**
     * @return Closure(string): bool
     */
    private function existenceOn(string $root, ?ProcessResult $tracked): Closure
    {
        if (!$tracked instanceof ProcessResult) {
            return static fn (string $path): bool => file_exists($root.'/'.$path);
        }

        $known = [];

        foreach (explode("\0", $tracked->output) as $file) {
            for ($path = $file; '' !== $path && '.' !== $path; $path = dirname($path)) {
                $known[$path] = true;
            }
        }

        return static fn (string $path): bool => isset($known[$path]);
    }

    /**
     * @return list<array{line: int, section: string, status: ?string, paths: list<string>}>
     */
    private function rowsOf(string $contents): array
    {
        $lines = $this->codeBlocks->blanked(explode("\n", $contents));
        $rows = [];
        $section = '';
        $columns = null;

        foreach ($lines as $index => $line) {
            if (1 === preg_match(self::HEADING, $line, $heading)) {
                $section = $heading[1];
            }

            $cells = $this->cellsOf($line);

            if (null === $cells) {
                $columns = null;

                continue;
            }

            if (null === $columns) {
                $columns = $this->columnsOf($cells, $lines[$index + 1] ?? '');

                continue;
            }

            if (!$this->isSeparator($cells)) {
                $rows[] = [
                    'line' => $index + 1,
                    'section' => $section,
                    'status' => false === $columns['status'] ? null : ($cells[$columns['status']] ?? ''),
                    'paths' => false === $columns['where'] ? [] : $this->pathsIn($cells[$columns['where']] ?? ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $cells
     *
     * @return array{status: int|false, where: int|false}|null
     */
    private function columnsOf(array $cells, string $next): ?array
    {
        $separator = $this->cellsOf($next);

        // Only a separator row makes a header, because outer pipes are optional and prose may hold a pipe.
        if (null === $separator || !$this->isSeparator($separator)) {
            return null;
        }

        return ['status' => array_search('Status', $cells, true), 'where' => array_search('Where', $cells, true)];
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
