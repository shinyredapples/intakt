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

    // Matches CommonMark, because ``a`b`` is one span and an escaped backtick opens none.
    private const string BACKTICKED = '/\\\\.(*SKIP)(*FAIL)|(`+)(?!`)(?:(.+?)(?<!`)\1(?!`)|(*SKIP)(*FAIL))/';

    // One space on each side is padding, so a span can start or end with a backtick (CommonMark).
    private const string PADDED_SPAN = '/^ (.*[^ ].*) $/';

    private const string PATH_LIKE = '#^(?:[^*<>{}]+/[^*<>{}]*|[^*<>{}/]*\.\w+)$#';

    private const string CELL_BORDER = '/(?<!\\\\)\|/';

    private const string OUTER_PIPES = '/^\||(?<!\\\\)\|$/';

    public function __construct(
        private FencedCodeBlocks $codeBlocks,
        private HighlightFragments $fragments,
    ) {
    }

    public function name(): string
    {
        return 'highlights';
    }

    public function run(Workspace $workspace): GateResult
    {
        $page = $this->fragments->pageOn($workspace->root);

        if (!$page instanceof HighlightAssembly) {
            return GateResult::passedBecause($this->name(), self::FILE.' does not exist');
        }

        $tracked = $workspace->hasGit() ? $workspace->git(['ls-files', '-z', '--cached']) : null;

        if ($tracked instanceof ProcessResult && !$tracked->succeeded()) {
            return GateResult::failedBecause($this->name(), 'cannot list tracked files: '.$tracked->firstErrorLine());
        }

        $findings = [
            ...$this->fragments->committedPageFindings($workspace->root, $tracked),
            ...$this->fragments->localPageFindings($workspace->root, $page),
            ...$page->findings,
            ...$this->findingsOf($page->text, $this->existenceOn($workspace->root, $tracked)),
        ];

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    /**
     * @return Closure(string): bool
     */
    private function existenceOn(string $root, ?ProcessResult $tracked): Closure
    {
        $exists = $this->trackedOrPresent($root, $tracked);

        return static fn (string $path): bool => $exists(preg_replace(self::LEADING_DOT_SLASH, '', $path) ?? $path);
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
     * @return Closure(string): bool
     */
    private function trackedOrPresent(string $root, ?ProcessResult $tracked): Closure
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

        if (false === $columns['where']) {
            return $findings;
        }

        $where = $cells[$columns['where']] ?? '';
        $findings = [...$findings, ...$this->whereFindings($where, $exists)];

        if (HighlightStatus::Planned === HighlightStatus::tryFrom($status ?? '') && $this->namesOnlyExistingPaths($where, $exists)) {
            $findings[] = 'row "'.$cells[0].'" is planned, but every path its Where names exists: update its status';
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
        // A backticked span is a path or a name, checked below, so `upstream/pr1-repro/` is no pull request.
        $unspanned = preg_replace(self::BACKTICKED, '', $where);

        // A pattern that gives up on a long cell (the backtrack limit) matches nothing, which must not read as a clean cell.
        if (null === $unspanned || false === preg_match_all(self::BACKTICKED, $where, $matches)) {
            return ['Where cannot be checked ('.preg_last_error_msg().'), shorten the cell'];
        }

        $findings = [];

        if (1 === preg_match(self::PULL_REQUEST_NUMBER, $unspanned, $match)) {
            $findings[] = 'Where cites a pull request ("'.$match[0].'"), cite the task and issue instead';
        }

        foreach ($this->pathsOf($matches[2]) as $path) {
            if (!$exists($path)) {
                $findings[] = 'Where names `'.$path.'`, which does not exist on the branch';
            }
        }

        return $findings;
    }

    // A cell without a path proves nothing built, so it never reads as done.
    /**
     * @param Closure(string): bool $exists
     */
    private function namesOnlyExistingPaths(string $where, Closure $exists): bool
    {
        if (false === preg_match_all(self::BACKTICKED, $where, $matches)) {
            return false;
        }

        $paths = $this->pathsOf($matches[2]);

        return [] !== $paths && array_all($paths, $exists);
    }

    /**
     * @param list<string> $spans
     *
     * @return list<string>
     */
    private function pathsOf(array $spans): array
    {
        $paths = [];

        foreach ($spans as $span) {
            // Falling back to the raw span keeps a failed match checked, since padding makes no tracked path.
            $path = preg_replace(self::PADDED_SPAN, '$1', $span) ?? $span;

            if (1 === preg_match(self::PATH_LIKE, $path)) {
                $paths[] = $path;
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
}
