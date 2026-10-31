<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Closure;

final readonly class HighlightsGate implements Gate
{
    private const string FILE = 'docs/highlights.md';

    // Any non-word characters between the label and the number, so "PR: #12", "PR (#12)" and "PR12" count like "PR #12".
    private const string PULL_REQUEST_NUMBER = '/\b(?:PRs?|pull requests?|pulls?)\W*\d+/i';

    private const string LEADING_DOT_SLASH = '#^(?:\./)+#';

    // A CommonMark code span closes only on a backtick run of its opening length, so ``a`b`` is one span;
    // a backslash escape outside a span is skipped, because \` is a literal backtick that opens nothing.
    private const string BACKTICKED = '/\\\\.(*SKIP)(*FAIL)|(`+)(?!`)(?:(.+?)(?<!`)\1(?!`)|(*SKIP)(*FAIL))/';

    // One space on each side is padding, so a span can start or end with a backtick (CommonMark).
    private const string PADDED_SPAN = '/^ (.*[^ ].*) $/';

    private const string PATH_LIKE = '#^(?:[^*<>{}]+/[^*<>{}]*|[^*<>{}/]*\.\w+)$#';

    private const string CELL_BORDER = '/(?<!\\\\)\|/';

    private const string OUTER_PIPES = '/^\||(?<!\\\\)\|$/';

    public function __construct(
        private FencedCodeBlocks $codeBlocks,
    ) {
    }

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

        $tracked = $workspace->hasGit() ? $workspace->git(['ls-files', '-z', '--cached']) : null;

        if ($tracked instanceof ProcessResult && !$tracked->succeeded()) {
            return GateResult::failedBecause($this->name(), 'cannot list tracked files: '.$tracked->firstErrorLine());
        }

        $findings = $this->findingsOf($contents, $this->existenceOn($workspace->root, $tracked));

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    /**
     * @param Closure(string): bool $exists
     *
     * @return list<string>
     */
    private function findingsOf(string $contents, Closure $exists): array
    {
        $lines = $this->codeBlocks->blanked(explode("\n", $contents));
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

            foreach ($this->rowFindings($cells, $columns, $exists) as $finding) {
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
     * @param Closure(string): bool                      $exists
     *
     * @return list<string>
     */
    private function rowFindings(array $cells, array $columns, Closure $exists): array
    {
        if ($this->isSeparator($cells)) {
            return [];
        }

        $findings = [];
        $status = false === $columns['status'] ? null : ($cells[$columns['status']] ?? '');

        if (null !== $status && null === HighlightStatus::tryFrom($status)) {
            $known = array_map(static fn (HighlightStatus $known): string => $known->value, HighlightStatus::cases());
            $findings[] = 'status "'.$status.'" is not one of '.implode(', ', $known);
        }

        if (false !== $columns['where']) {
            $findings = [...$findings, ...$this->whereFindings($cells[$columns['where']] ?? '', $exists)];
        }

        return $findings;
    }

    /**
     * @param Closure(string): bool $exists
     *
     * @return list<string>
     */
    private function whereFindings(string $where, Closure $exists): array
    {
        $findings = [];

        // A backticked span is a path or a name, checked below, so `upstream/pr1-repro/` is no pull request.
        if (1 === preg_match(self::PULL_REQUEST_NUMBER, (string) preg_replace(self::BACKTICKED, '', $where), $match)) {
            $findings[] = 'Where cites a pull request ("'.$match[0].'"), cite the task and issue instead';
        }

        preg_match_all(self::BACKTICKED, $where, $matches);

        foreach ($matches[2] as $span) {
            $path = (string) preg_replace(self::PADDED_SPAN, '$1', $span);

            if (1 === preg_match(self::PATH_LIKE, $path) && !$exists((string) preg_replace(self::LEADING_DOT_SLASH, '', $path))) {
                $findings[] = 'Where names `'.$path.'`, which does not exist on the branch';
            }
        }

        return $findings;
    }

    /**
     * @return Closure(string): bool
     */
    private function existenceOn(string $root, ?ProcessResult $tracked): Closure
    {
        // Without git there is no branch, so the working tree stands in for it.
        if (!$tracked instanceof ProcessResult) {
            return static fn (string $path): bool => file_exists($root.'/'.$path);
        }

        $known = [];

        foreach (explode("\0", $tracked->output) as $file) {
            for ($path = $file; '' !== $path && '.' !== $path; $path = dirname($path)) {
                $known[$path] = true;
            }
        }

        return static fn (string $path): bool => isset($known[rtrim($path, '/')]);
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
