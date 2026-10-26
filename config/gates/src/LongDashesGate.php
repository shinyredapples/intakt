<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class LongDashesGate implements Gate
{
    private const int BINARY_PROBE_LENGTH = 8000;

    public function name(): string
    {
        return 'dashes';
    }

    public function run(Workspace $workspace): GateResult
    {
        try {
            $findings = [...$this->fileFindings($workspace), ...$this->commitFindings($workspace)];
        } catch (ScopeFailure $failure) {
            return GateResult::failedBecause($this->name(), $failure->getMessage());
        }

        if ([] !== $findings) {
            return GateResult::withFindings($this->name(), $findings);
        }

        return $workspace->hasGit() ? GateResult::passed($this->name()) : GateResult::passedBecause($this->name(), 'commits skipped, no git history in this tree');
    }

    /**
     * @return list<string>
     */
    private function fileFindings(Workspace $workspace): array
    {
        $findings = [];

        foreach ($workspace->repositoryFiles() as $path) {
            foreach ((new LongDashLines($this->textOf($workspace->root.'/'.$path)))->codePointsByLineNumber() as $line => $codePoints) {
                $findings[] = $path.':'.$line.': '.$codePoints;
            }
        }

        return $findings;
    }

    private function textOf(string $file): string
    {
        if (!is_file($file) || is_link($file)) {
            return '';
        }

        $contents = file_get_contents($file);

        if (false === $contents) {
            throw new ScopeFailure('cannot read '.$file);
        }

        return str_contains(substr($contents, 0, self::BINARY_PROBE_LENGTH), "\0") ? '' : $contents;
    }

    /**
     * @return list<string>
     */
    private function commitFindings(Workspace $workspace): array
    {
        if (!$workspace->hasGit()) {
            return [];
        }

        $findings = [];

        foreach ($this->commitsOfTheChange($workspace) as $commit) {
            foreach ((new LongDashLines($this->messageOf($workspace, $commit)))->codePointsByLineNumber() as $line => $codePoints) {
                $findings[] = 'commit '.substr($commit, 0, 8).' '.(1 === $line ? 'subject' : 'message line '.$line).': '.$codePoints;
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function commitsOfTheChange(Workspace $workspace): array
    {
        $mergeBase = $workspace->mergeBase();

        if (null === $mergeBase) {
            throw new ScopeFailure('no base branch found, pass --base=<ref>');
        }

        return $this->lines($this->gitOutput($workspace, ['rev-list', '--reverse', $mergeBase.'..HEAD']));
    }

    private function messageOf(Workspace $workspace, string $commit): string
    {
        return $this->gitOutput($workspace, ['log', '--max-count=1', '--format=%B', $commit]);
    }

    /**
     * @param list<string> $arguments
     */
    private function gitOutput(Workspace $workspace, array $arguments): string
    {
        $result = $workspace->command(['git', ...$arguments]);

        if (!$result->succeeded()) {
            throw new ScopeFailure('git '.$arguments[0].' failed: '.$result->firstErrorLine());
        }

        return $result->output;
    }

    /**
     * @return list<string>
     */
    private function lines(string $output): array
    {
        return array_values(array_filter(explode("\n", $output), static fn (string $line): bool => '' !== $line));
    }
}
