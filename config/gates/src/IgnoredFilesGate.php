<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class IgnoredFilesGate implements Gate
{
    // Only the .gitignore files count, because info/exclude and core.excludesFile are local and no change can see them.
    private const array TRACKED_AND_IGNORED = ['ls-files', '-z', '--cached', '--ignored', '--exclude-per-directory=.gitignore'];

    // Because ls-files reads the working-tree .gitignore files, an unstaged edit could hide a path the committed rules exclude.
    private const array UNSTAGED_RULES = ['diff', '--name-only', '-z', '--', ':(glob)**/.gitignore'];

    private const array ESCAPES = ["\x07" => '\a', "\x08" => '\b', "\t" => '\t', "\n" => '\n', "\x0B" => '\v', "\x0C" => '\f', "\r" => '\r', '"' => '\"', '\\' => '\\\\'];

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

        $rules = $workspace->gitWithPathspecMagic(self::UNSTAGED_RULES);

        if (!$rules->succeeded()) {
            return GateResult::failedBecause($this->name(), 'git diff failed: '.$rules->firstErrorLine());
        }

        $findings = [
            ...array_map($this->unstagedRulesFinding(...), $this->paths($rules->output)),
            ...array_map($this->trackedFinding(...), $this->paths($listing->output)),
        ];

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    private function unstagedRulesFinding(string $path): string
    {
        return $this->quoted($path).': working-tree .gitignore differs from the index; stage or revert it so the gate judges the rules that will be committed';
    }

    private function trackedFinding(string $path): string
    {
        return $this->quoted($path).': tracked although .gitignore excludes it; run git rm --cached on it, or add a negated rule to .gitignore';
    }

    // Because a finding must name a file the reader can untrack, a path JSON cannot carry losslessly is quoted the way git quotes it.
    private function quoted(string $path): string
    {
        if (0 === preg_match('/[\x00-\x1F\x7F]/u', $path)) {
            return $path;
        }

        $quoted = '';

        foreach (str_split($path) as $byte) {
            $quoted .= self::ESCAPES[$byte] ?? $this->escapedByte($byte);
        }

        return '"'.$quoted.'"';
    }

    private function escapedByte(string $byte): string
    {
        $code = ord($byte);

        return $code < 0x20 || $code >= 0x7F ? sprintf('\%03o', $code) : $byte;
    }

    /**
     * @return list<string>
     */
    private function paths(string $output): array
    {
        return array_values(array_unique(array_filter(explode("\0", $output), static fn (string $path): bool => '' !== $path)));
    }
}
