<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class HighlightsGate implements Gate
{
    private const string FILE = 'docs/highlights.md';

    // Any non-word characters between the label and the number, so "PR: #12", "PR (#12)" and "PR12" count like "PR #12".
    private const string PULL_REQUEST_NUMBER = '/\b(?:PRs?|pull requests?|pulls?)\W*\d+/i';

    public function __construct(
        private HighlightsRows $rows,
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

        $findings = $this->findingsOf($contents, PathsOnBranch::of($workspace->root, $tracked));

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    /**
     * @return list<string>
     */
    private function findingsOf(string $contents, PathsOnBranch $branch): array
    {
        $findings = [];

        foreach ($this->rows->of($contents) as $row) {
            foreach ($this->rowFindings($row, $branch) as $finding) {
                $findings[] = self::FILE.':'.$row->line.': '.$finding;
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function rowFindings(HighlightsRow $row, PathsOnBranch $branch): array
    {
        $findings = [];

        if (null !== $row->status && null === HighlightStatus::tryFrom($row->status)) {
            $known = array_map(static fn (HighlightStatus $known): string => $known->value, HighlightStatus::cases());
            $findings[] = 'status "'.$row->status.'" is not one of '.implode(', ', $known);
        }

        return [...$findings, ...$this->whereFindings($row, $branch)];
    }

    /**
     * @return list<string>
     */
    private function whereFindings(HighlightsRow $row, PathsOnBranch $branch): array
    {
        // A backticked span is a path or a name, checked below, so `upstream/pr1-repro/` is no pull request.
        $unspanned = $row->whereOutsideSpans();
        $paths = null === $unspanned ? null : $row->paths();

        if (null === $unspanned || null === $paths) {
            return ['Where cannot be checked ('.preg_last_error_msg().'), shorten the cell'];
        }

        $findings = [];

        if (1 === preg_match(self::PULL_REQUEST_NUMBER, $unspanned, $match)) {
            $findings[] = 'Where cites a pull request ("'.$match[0].'"), cite the task and issue instead';
        }

        foreach ($paths as $path) {
            if (!$branch->exists($path)) {
                $findings[] = 'Where names `'.$path.'`, which does not exist on the branch';
            }
        }

        return $findings;
    }
}
