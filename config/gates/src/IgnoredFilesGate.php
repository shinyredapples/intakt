<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class IgnoredFilesGate implements Gate
{
    // Only the .gitignore files count, because info/exclude and core.excludesFile are local and no change can see them.
    private const array TRACKED_AND_IGNORED = ['ls-files', '-z', '--cached', '--ignored', '--exclude-per-directory=.gitignore'];

    // Because ls-files reads the working-tree .gitignore files, an unstaged edit could hide a path the committed rules exclude.
    private const array UNSTAGED_RULES = ['diff', '--name-only', '-z', '--', ':(glob)**/.gitignore'];

    // Because git diff skips entries marked assume-unchanged or skip-worktree and never sees untracked files, those rule files are listed with their tags; no exclude applies, so a .gitignore that ignores itself is listed too.
    private const array RULE_FILES = ['ls-files', '-z', '-v', '--cached', '--others', '--', ':(glob)**/.gitignore'];

    private const string UNTRACKED_TAG = '?';

    private const string SKIP_WORKTREE_TAG = 'S';

    private const string DIFFERS = 'working-tree .gitignore differs from the index; stage or revert it so the gate judges the rules that will be committed';

    private const string MARKED = 'marked assume-unchanged or skip-worktree; clear the mark (git update-index --no-assume-unchanged --no-skip-worktree) so the gate can compare it with the index';

    private const string UNTRACKED = 'untracked .gitignore above tracked files; add or remove it so the gate judges the rules that will be committed';

    private const string TRACKED = 'tracked although .gitignore excludes it; run git rm --cached on it, or add a negated rule to .gitignore';

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

        $ruleFiles = $workspace->gitWithPathspecMagic(self::RULE_FILES);

        if (!$ruleFiles->succeeded()) {
            return GateResult::failedBecause($this->name(), 'git ls-files failed: '.$ruleFiles->firstErrorLine());
        }

        $tagged = $this->taggedPaths($ruleFiles->output);
        $untracked = array_keys(array_filter($tagged, static fn (string $tag): bool => self::UNTRACKED_TAG === $tag));
        $shadowing = $this->rulesAboveTrackedFiles($workspace, array_map(strval(...), $untracked));

        if ($shadowing instanceof GateResult) {
            return $shadowing;
        }

        $reasons = [];

        foreach ($this->paths($rules->output) as $path) {
            $reasons[$path][] = self::DIFFERS;
        }

        foreach ($tagged as $path => $tag) {
            if (self::SKIP_WORKTREE_TAG === $tag || ctype_lower($tag)) {
                $reasons[$path][] = self::MARKED;
            }
        }

        foreach ($shadowing as $path) {
            $reasons[$path][] = self::UNTRACKED;
        }

        foreach ($this->paths($listing->output) as $path) {
            $reasons[$path][] = self::TRACKED;
        }

        $findings = [];

        foreach ($reasons as $path => $pathReasons) {
            $findings[] = $this->quoted((string) $path).': '.implode('; ', array_unique($pathReasons));
        }

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    /**
     * @return list<string>
     */
    private function paths(string $output): array
    {
        return array_values(array_unique(array_filter(explode("\0", $output), static fn (string $path): bool => '' !== $path)));
    }

    /**
     * @return array<string, string>
     */
    private function taggedPaths(string $output): array
    {
        $tagged = [];

        foreach ($this->paths($output) as $entry) {
            $tagged[substr($entry, 2)] = $entry[0];
        }

        return $tagged;
    }

    /**
     * @param list<string> $untracked
     *
     * @return list<string>|GateResult
     */
    private function rulesAboveTrackedFiles(Workspace $workspace, array $untracked): array|GateResult
    {
        if ([] === $untracked) {
            return [];
        }

        $pathspecs = array_map(fn (string $path): string => '' === $this->directoryOf($path) ? '.' : $this->directoryOf($path), $untracked);
        $tracked = $workspace->git(['ls-files', '-z', '--cached', '--', ...$pathspecs]);

        if (!$tracked->succeeded()) {
            return GateResult::failedBecause($this->name(), 'git ls-files failed: '.$tracked->firstErrorLine());
        }

        $trackedPaths = $this->paths($tracked->output);

        return array_values(array_filter($untracked, fn (string $path): bool => array_any($trackedPaths, fn (string $trackedPath): bool => str_starts_with($trackedPath, $this->directoryOf($path)))));
    }

    private function directoryOf(string $rulePath): string
    {
        return substr($rulePath, 0, -\strlen('.gitignore'));
    }

    // Because a finding must name a file the reader can untrack, a path JSON cannot carry losslessly is quoted the way git quotes it.
    private function quoted(string $path): string
    {
        if (0 === preg_match('/\p{Cc}/u', $path)) {
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
        $code = ord($byte[0]);

        return $code < 0x20 || $code >= 0x7F ? sprintf('\%03o', $code) : $byte;
    }
}
