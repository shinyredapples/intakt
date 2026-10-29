<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class HighlightsGate implements Gate
{
    private const string FILE = 'docs/highlights.md';

    private const array STATUSES = ['planned', 'in progress', 'done'];

    private const string PULL_REQUEST_NUMBER = '/\b(?:PRs?|pull requests?|pulls?)\b[\s\/]*#?\d+/i';

    private const string BACKTICKED = '/`([^`]+)`/';

    private const string PATH_LIKE = '#^(?:[^\s*<>{}]+/[^\s*<>{}]*|[\w.-]+\.(?:php|md|json|yaml|yml|xml|toml|neon|dist))$#';

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
        $findings = [];
        $statusColumn = null;
        $whereColumn = null;

        foreach (explode("\n", $contents) as $index => $line) {
            $cells = $this->cellsOf($line);

            if (null === $cells) {
                [$statusColumn, $whereColumn] = [null, null];

                continue;
            }

            if (null === $statusColumn) {
                $statusColumn = array_search('Status', $cells, true);
                $whereColumn = array_search('Where', $cells, true);

                continue;
            }

            foreach ($this->rowFindings($cells, $statusColumn, $whereColumn, $root) as $finding) {
                $findings[] = self::FILE.':'.($index + 1).': '.$finding;
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $cells
     *
     * @return list<string>
     */
    private function rowFindings(array $cells, int|false $statusColumn, int|false $whereColumn, string $root): array
    {
        if ($this->isSeparator($cells)) {
            return [];
        }

        $findings = [];
        $status = false === $statusColumn ? null : ($cells[$statusColumn] ?? '');

        if (null !== $status && !in_array($status, self::STATUSES, true)) {
            $findings[] = 'status "'.$status.'" is not one of '.implode(', ', self::STATUSES);
        }

        if (false !== $whereColumn) {
            $findings = [...$findings, ...$this->whereFindings($cells[$whereColumn] ?? '', $root)];
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

        if (!str_starts_with($line, '|')) {
            return null;
        }

        $cells = preg_split('/(?<!\\\\)\|/', substr($line, 1, str_ends_with($line, '|') ? -1 : null));

        return false === $cells ? null : array_map(trim(...), $cells);
    }
}
