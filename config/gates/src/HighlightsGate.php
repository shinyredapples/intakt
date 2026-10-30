<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class HighlightsGate implements Gate
{
    private const string FILE = 'docs/highlights.md';

    private const array STATUSES = ['planned', 'in progress', 'done'];

    private const string PULL_REQUEST_NUMBER = '/\b(?:PRs?|pull requests?|pulls?)\b[\s\/]*#?\d+/i';

    private const string BACKTICKED = '/`([^`]+)`/';

    private const string PATH_LIKE = '#^(?:[^\s*<>{}]+/[^\s*<>{}]*|[\w.-]*\.\w+)$#';

    private const string CELL_BORDER = '/(?<!\\\\)\|/';

    private const string OUTER_PIPES = '/^\||(?<!\\\\)\|$/';

    public function name(): string
    {
        return 'highlights';
    }

    public function run(Workspace $workspace): GateResult
    {
        $file = $workspace->root.'/'.self::FILE;

        if (!is_file($file)) {
            return GateResult::passedBecause($this->name(), self::FILE.' does not exist');
        }

        $contents = file_get_contents($file);

        if (false === $contents) {
            return GateResult::failedBecause($this->name(), 'cannot read '.self::FILE);
        }

        $findings = $this->findingsOf($contents, $workspace->root);

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    /**
     * @return list<string>
     */
    private function findingsOf(string $contents, string $root): array
    {
        $lines = explode("\n", $contents);
        $findings = [];
        $columns = null;

        foreach ($lines as $index => $line) {
            $cells = $this->cellsOf($line);

            if (null === $cells) {
                $columns = null;

                continue;
            }

            if (null === $columns) {
                $columns = $this->columnsOf($cells, $lines[$index + 1] ?? '');

                continue;
            }

            foreach ($this->rowFindings($cells, $columns, $root) as $finding) {
                $findings[] = self::FILE.':'.($index + 1).': '.$finding;
            }
        }

        return $findings;
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
     * @param list<string>                               $cells
     * @param array{status: int|false, where: int|false} $columns
     *
     * @return list<string>
     */
    private function rowFindings(array $cells, array $columns, string $root): array
    {
        if ($this->isSeparator($cells)) {
            return [];
        }

        $findings = [];
        $status = false === $columns['status'] ? null : ($cells[$columns['status']] ?? '');

        if (null !== $status && !in_array($status, self::STATUSES, true)) {
            $findings[] = 'status "'.$status.'" is not one of '.implode(', ', self::STATUSES);
        }

        if (false !== $columns['where']) {
            $findings = [...$findings, ...$this->whereFindings($cells[$columns['where']] ?? '', $root)];
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function whereFindings(string $where, string $root): array
    {
        $findings = [];

        if (1 === preg_match(self::PULL_REQUEST_NUMBER, $where, $match)) {
            $findings[] = 'Where cites a pull request ("'.$match[0].'"), cite the task and issue instead';
        }

        preg_match_all(self::BACKTICKED, $where, $matches);

        foreach ($matches[1] as $path) {
            if (1 === preg_match(self::PATH_LIKE, $path) && !file_exists($root.'/'.$path)) {
                $findings[] = 'Where names `'.$path.'`, which does not exist';
            }
        }

        return $findings;
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

        return false === $cells ? null : array_map(trim(...), $cells);
    }
}
