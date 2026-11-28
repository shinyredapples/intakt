<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class IgnoredFilesGate implements Gate
{
    // Only the .gitignore files count, because info/exclude and core.excludesFile are local and no change can see them.
    private const array TRACKED_AND_IGNORED = ['ls-files', '-z', '--cached', '--ignored', '--exclude-per-directory=.gitignore'];

    // Because ls-files reads the working-tree .gitignore files, an unstaged edit could hide a path the committed rules exclude; the staged blob ids are compared with the working-tree content, since git diff trusts stat data that an edit can keep.
    private const array STAGED_RULES = ['ls-files', '-z', '--stage', '--', ':(glob)**/.gitignore'];

    // Because the content comparison leaves out entries marked assume-unchanged or skip-worktree and never sees untracked files, those rule files are listed with their tags; no exclude applies, so a .gitignore that ignores itself is listed too.
    private const array RULE_FILES = ['ls-files', '-z', '-v', '--cached', '--others', '--', ':(glob)**/.gitignore'];

    private const string UNTRACKED_TAG = '?';

    private const string SKIP_WORKTREE_TAG = 'S';

    private const string SYMLINK_MODE = '120000';

    private const string DIFFERS = 'working-tree .gitignore differs from the index; stage or revert it so the gate judges the rules that will be committed';

    private const string MARKED = 'marked assume-unchanged or skip-worktree; clear each mark with its own command (git update-index --no-assume-unchanged <path>, then git update-index --no-skip-worktree <path>) so the gate can compare it with the index';

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
        $staged = $workspace->gitWithPathspecMagic(self::STAGED_RULES);
        $ruleFiles = $workspace->gitWithPathspecMagic(self::RULE_FILES);
        $failure = $this->failureOf([[self::TRACKED_AND_IGNORED, $listing], [self::STAGED_RULES, $staged], [self::RULE_FILES, $ruleFiles]]);

        if ($failure instanceof GateResult) {
            return $failure;
        }

        $tagged = $this->taggedPaths($ruleFiles->output);
        $differing = $this->rulesDifferingFromTheIndex($workspace, $this->stagedBlobs($staged->output, $tagged));

        if ($differing instanceof GateResult) {
            return $differing;
        }

        $shadowing = $this->rulesAboveTrackedFiles($workspace, array_keys(array_filter($tagged, static fn (string $tag): bool => self::UNTRACKED_TAG === $tag)));

        if ($shadowing instanceof GateResult) {
            return $shadowing;
        }

        return $this->resultOf([
            ...$this->reasoned($differing, self::DIFFERS),
            ...$this->reasoned(array_keys(array_filter($tagged, $this->isMarked(...))), self::MARKED),
            ...$this->reasoned($shadowing, self::UNTRACKED),
            ...$this->reasoned($this->paths($listing->output), self::TRACKED),
        ]);
    }

    /**
     * @param list<array{list<string>, ProcessResult}> $calls
     */
    private function failureOf(array $calls): ?GateResult
    {
        foreach ($calls as [$arguments, $result]) {
            if (!$result->succeeded()) {
                return GateResult::failedBecause($this->name(), 'git '.$arguments[0].' failed: '.$result->firstErrorLine());
            }
        }

        return null;
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
     * Git does not read a .gitignore that is a symbolic link, so a staged link holds no rule to compare.
     *
     * @param array<string, string> $tagged
     *
     * @return array<string, list<string>>
     */
    private function stagedBlobs(string $output, array $tagged): array
    {
        $blobs = [];

        foreach ($this->paths($output) as $entry) {
            [$stage, $path] = explode("\t", $entry, 2);
            [$mode, $blob] = explode(' ', $stage);

            if (self::SYMLINK_MODE !== $mode && !$this->isMarked($tagged[$path] ?? '')) {
                $blobs[$path][] = $blob;
            }
        }

        return $blobs;
    }

    /**
     * @param array<string, list<string>> $staged
     *
     * @return list<string>|GateResult
     */
    private function rulesDifferingFromTheIndex(Workspace $workspace, array $staged): array|GateResult
    {
        $present = array_values(array_filter(array_keys($staged), static fn (string $path): bool => is_file($workspace->root.'/'.$path) && !is_link($workspace->root.'/'.$path)));
        $missing = array_values(array_diff(array_keys($staged), $present));

        if ([] === $present) {
            return $missing;
        }

        $arguments = ['hash-object', '--', ...$present];
        $hashed = $workspace->git($arguments);
        $failure = $this->failureOf([[$arguments, $hashed]]);

        if ($failure instanceof GateResult) {
            return $failure;
        }

        $blobs = explode("\n", trim($hashed->output));

        return [...$missing, ...array_values(array_filter($present, static fn (string $path, int $index): bool => !in_array($blobs[$index] ?? '', $staged[$path], true), ARRAY_FILTER_USE_BOTH))];
    }

    /**
     * @param list<string> $untracked
     *
     * @return list<string>|GateResult
     */
    private function rulesAboveTrackedFiles(Workspace $workspace, array $untracked): array|GateResult
    {
        // Git does not read a .gitignore that is a symbolic link, so such a link cannot hide a tracked file.
        $untracked = array_values(array_filter($untracked, static fn (string $path): bool => !is_link($workspace->root.'/'.$path)));

        if ([] === $untracked) {
            return [];
        }

        $pathspecs = array_map(fn (string $path): string => '' === $this->directoryOf($path) ? '.' : $this->directoryOf($path), $untracked);
        $arguments = ['ls-files', '-z', '--cached', '--', ...$pathspecs];
        $tracked = $workspace->git($arguments);
        $failure = $this->failureOf([[$arguments, $tracked]]);

        if ($failure instanceof GateResult) {
            return $failure;
        }

        $trackedPaths = $this->paths($tracked->output);

        return array_values(array_filter($untracked, fn (string $path): bool => array_any($trackedPaths, fn (string $trackedPath): bool => str_starts_with($trackedPath, $this->directoryOf($path)))));
    }

    private function directoryOf(string $rulePath): string
    {
        return substr($rulePath, 0, -strlen('.gitignore'));
    }

    /**
     * @return list<string>
     */
    private function paths(string $output): array
    {
        return array_values(array_unique(array_filter(explode("\0", $output), static fn (string $path): bool => '' !== $path)));
    }

    /**
     * @param list<string> $paths
     *
     * @return list<array{string, string}>
     */
    private function reasoned(array $paths, string $reason): array
    {
        return array_map(static fn (string $path): array => [$path, $reason], $paths);
    }

    private function isMarked(string $tag): bool
    {
        return self::SKIP_WORKTREE_TAG === $tag || ctype_lower($tag);
    }

    /**
     * @param list<array{string, string}> $reasons
     */
    private function resultOf(array $reasons): GateResult
    {
        $findings = [];

        foreach (array_unique(array_column($reasons, 0)) as $path) {
            $pathReasons = array_column(array_filter($reasons, static fn (array $reason): bool => $reason[0] === $path), 1);
            $findings[] = $this->quoted($path).': '.implode('; ', array_unique($pathReasons));
        }

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
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
