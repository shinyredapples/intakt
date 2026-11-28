<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Refuses tracked files that the `.gitignore` files exclude; local excludes (.git/info/exclude, core.excludesFile) do not count, because they differ per clone.
final readonly class IgnoredFilesGate implements Gate
{
    private const string DIFFERING_RULES = 'working-tree .gitignore differs from the index; stage or revert it so the gate judges the rules that will be committed';

    public function name(): string
    {
        return 'ignored';
    }

    public function run(Workspace $workspace): GateResult
    {
        if (!$workspace->hasGit()) {
            return GateResult::passedBecause($this->name(), 'no git index in this tree');
        }

        try {
            $ignored = $this->trackedIgnoredPaths($workspace);
            $differing = $this->ruleFilesDifferingFromTheIndex($workspace);
        } catch (ScopeFailure $failure) {
            return GateResult::failedBecause($this->name(), $failure->getMessage());
        }

        $findings = [
            ...array_map(fn (string $path): string => $this->shown($path).': tracked, but .gitignore excludes it', $ignored),
            ...array_map(fn (string $path): string => $this->shown($path).': '.self::DIFFERING_RULES, $differing),
        ];

        if ([] === $findings) {
            return GateResult::passed($this->name());
        }

        return GateResult::withFindings($this->name(), $findings);
    }

    /**
     * @return list<string>
     */
    private function trackedIgnoredPaths(Workspace $workspace): array
    {
        $arguments = ['ls-files', '-z', '--cached', '--ignored', '--exclude-per-directory=.gitignore'];
        $result = $workspace->git($arguments);

        if (!$result->succeeded()) {
            throw new ScopeFailure('git ls-files failed: '.$result->firstErrorLine());
        }

        // Because git lists a conflicted path once per index stage.
        return explode("\0", $result->output)
            |> (static fn (array $paths): array => array_filter($paths, static fn (string $path): bool => '' !== $path))
            |> array_unique(...)
            |> array_values(...);
    }

    // Because ls-files reads the working-tree rules, an unstaged edit could hide a tracked file that CI, judging the committed rules, rejects; blob ids, not stat data, so an edit that keeps size and mtime still counts.
    /**
     * @return list<string>
     */
    private function ruleFilesDifferingFromTheIndex(Workspace $workspace): array
    {
        $staged = $this->stagedRuleFiles($workspace);
        $present = array_filter(array_keys($staged), static fn (string $path): bool => is_file($workspace->root.'/'.$path) && !is_link($workspace->root.'/'.$path));
        $hashes = $this->workingTreeBlobs($workspace, array_values($present));

        return array_keys($staged)
            |> (static fn (array $paths): array => array_filter($paths, static fn (string $path): bool => ($hashes[$path] ?? null) !== $staged[$path]))
            |> array_values(...);
    }

    // Because git reads no .gitignore that is a symbolic link, neither here nor in CI, a tracked link carries no rules to compare; only stage 0, so a conflicted file is not compared three times.
    /**
     * @return array<string, string>
     */
    private function stagedRuleFiles(Workspace $workspace): array
    {
        $result = $workspace->git(['ls-files', '-s', '-z']);

        if (!$result->succeeded()) {
            throw new ScopeFailure('git ls-files failed: '.$result->firstErrorLine());
        }

        $staged = [];
        foreach (explode("\0", $result->output) as $entry) {
            if (1 === preg_match('/\A(?<mode>\d+) (?<blob>[0-9a-f]+) 0\t(?<path>(?:.*\/)?\.gitignore)\z/s', $entry, $match) && '120000' !== $match['mode']) {
                $staged[$match['path']] = $match['blob'];
            }
        }

        return $staged;
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, string>
     */
    private function workingTreeBlobs(Workspace $workspace, array $paths): array
    {
        if ([] === $paths) {
            return [];
        }

        $result = $workspace->git(['hash-object', '--', ...$paths]);

        if (!$result->succeeded()) {
            throw new ScopeFailure('git hash-object failed: '.$result->firstErrorLine());
        }

        return array_combine($paths, explode("\n", rtrim($result->output, "\n")));
    }

    // Because the full report must name a file the reader can untrack, a path JSON would mangle (not UTF-8), a line break or control would split or hide (C0, DEL, C1 such as U+0085, U+2028, U+2029) or a format character would reorder (U+202E) is quoted as git quotes it; \z, not $, so a trailing newline counts.
    private function shown(string $path): string
    {
        $plain = 1 === preg_match('/\A[^\p{Cc}\p{Cf}\p{Zl}\p{Zp}]*\z/u', $path) && !str_starts_with($path, '"');

        return $plain ? $path : '"'.addcslashes($path, "\0..\37\"\\\177..\377").'"';
    }
}
