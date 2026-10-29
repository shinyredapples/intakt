<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class CommitSubjectsGate implements Gate
{
    private const string CONVENTIONAL_SUBJECT = '/^(feat|fix|docs|refactor|test|perf|build|ci|chore|revert|style)(\([a-z0-9._\/-]+\))?!?: \S.*$/';
    private const int MAXIMUM_LENGTH = 100;

    public function __construct(private ?string $testMerge = null)
    {
    }

    public function name(): string
    {
        return 'commits';
    }

    public function run(Workspace $workspace): GateResult
    {
        $mergeBase = $workspace->mergeBase();

        if (!$workspace->hasGit()) {
            return GateResult::passedBecause($this->name(), 'skipped, no git history in this tree');
        }

        if (null === $mergeBase) {
            return GateResult::failedBecause($this->name(), 'no base branch found, pass --base=<ref>');
        }

        $log = $workspace->git(['log', '--format=%H%x09%P%x09%s', $mergeBase.'..HEAD']);

        if (!$log->succeeded()) {
            return GateResult::failedBecause($this->name(), 'git log failed: '.$log->firstErrorLine());
        }

        $findings = $this->nonConformingCommits($workspace, $log->output);

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    /**
     * @return list<string>
     */
    private function nonConformingCommits(Workspace $workspace, string $log): array
    {
        $findings = [];
        $lines = array_values(array_filter(explode("\n", $log), static fn (string $line): bool => '' !== $line));

        foreach ($lines as $line) {
            [$hash, $parents, $subject] = explode("\t", $line, 3) + ['', '', ''];

            if (!$this->isGithubTestMerge($workspace, $hash, $parents) && !$this->conforms($subject)) {
                $findings[] = substr($hash, 0, 8).' '.$subject;
            }
        }

        return $findings;
    }

    private function isGithubTestMerge(Workspace $workspace, string $hash, string $parents): bool
    {
        if (null === $this->testMerge) {
            return false;
        }

        $fields = explode(':', $this->testMerge);
        $parentHashes = explode(' ', $parents);

        if (2 !== count($fields)) {
            return false;
        }

        [$merge, $head] = $fields;

        if ('' === $merge || $hash !== $merge || 2 !== count($parentHashes) || $parentHashes[1] !== $head) {
            return false;
        }

        return $this->isOnBaseBranch($workspace, $parentHashes[0]);
    }

    // Because GitHub builds the test merge on the current base tip, which can be newer than the base the event reports.
    private function isOnBaseBranch(Workspace $workspace, string $commit): bool
    {
        $baseTip = $workspace->baseTip();

        return null !== $baseTip && $workspace->git(['merge-base', '--is-ancestor', $commit, $baseTip])->succeeded();
    }

    private function conforms(string $subject): bool
    {
        return mb_strlen($subject) <= self::MAXIMUM_LENGTH && 1 === preg_match(self::CONVENTIONAL_SUBJECT, $subject);
    }
}
