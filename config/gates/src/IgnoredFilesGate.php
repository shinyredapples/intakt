<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class IgnoredFilesGate implements Gate
{
    // Only the committed .gitignore files count, because info/exclude and core.excludesFile are local and no change can see them.
    private const array TRACKED_AND_IGNORED = ['ls-files', '-z', '--cached', '--ignored', '--exclude-per-directory=.gitignore'];

    public function name(): string
    {
        return 'ignored';
    }

    public function run(Workspace $workspace): GateResult
    {
        if (!$workspace->hasGit()) {
            return GateResult::passedBecause($this->name(), 'skipped, no git history in this tree');
        }

        $listing = $workspace->git(self::TRACKED_AND_IGNORED);

        if (!$listing->succeeded()) {
            return GateResult::failedBecause($this->name(), 'git ls-files failed: '.$listing->firstErrorLine());
        }

        $findings = array_map($this->findingFor(...), $this->paths($listing->output));

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    private function findingFor(string $path): string
    {
        return $path.': tracked although .gitignore excludes it; run git rm --cached on it, or add a negated rule to .gitignore';
    }

    /**
     * @return list<string>
     */
    private function paths(string $output): array
    {
        return array_values(array_filter(explode("\0", $output), static fn (string $path): bool => '' !== $path));
    }
}
