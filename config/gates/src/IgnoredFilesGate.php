<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Refuses tracked files that the `.gitignore` files exclude; local excludes (.git/info/exclude, core.excludesFile) do not count, because they differ per clone.
final readonly class IgnoredFilesGate implements Gate
{
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
            $paths = $this->trackedIgnoredPaths($workspace);
        } catch (ScopeFailure $failure) {
            return GateResult::failedBecause($this->name(), $failure->getMessage());
        }

        if ([] === $paths) {
            return GateResult::passed($this->name());
        }

        return GateResult::withFindings($this->name(), array_map(fn (string $path): string => $this->shown($path).': tracked, but .gitignore excludes it', $paths));
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

    // Because the full report must name a file the reader can untrack, a path JSON would mangle (not UTF-8), a line break or control would split or hide (C0, DEL, C1 such as U+0085, U+2028, U+2029) or a format character would reorder (U+202E) is quoted as git quotes it; \z, not $, so a trailing newline counts.
    private function shown(string $path): string
    {
        $plain = 1 === preg_match('/\A[^\p{Cc}\p{Cf}\p{Zl}\p{Zp}]*\z/u', $path) && !str_starts_with($path, '"');

        return $plain ? $path : '"'.addcslashes($path, "\0..\37\"\\\177..\377").'"';
    }
}
