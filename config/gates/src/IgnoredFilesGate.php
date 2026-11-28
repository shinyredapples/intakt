<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Refuses tracked files that the `.gitignore` files exclude; local excludes (.git/info/exclude, core.excludesFile) do not count, because they differ per clone.
final readonly class IgnoredFilesGate implements Gate
{
    private const array C_ESCAPES = ["\x07" => 'a', "\x08" => 'b', "\t" => 't', "\n" => 'n', "\v" => 'v', "\f" => 'f', "\r" => 'r', '"' => '"', '\\' => '\\'];

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

    // Because the full report must name a file the reader can untrack, a path JSON would mangle or a line break would split is quoted as git quotes it.
    private function shown(string $path): string
    {
        $plain = 1 === preg_match('//u', $path) && 1 !== preg_match('/[\x00-\x1F\x7F]/', $path) && !str_starts_with($path, '"');

        return $plain ? $path : '"'.implode('', array_map($this->quotedByte(...), str_split($path))).'"';
    }

    private function quotedByte(string $byte): string
    {
        if (isset(self::C_ESCAPES[$byte])) {
            return '\\'.self::C_ESCAPES[$byte];
        }

        $code = ord($byte[0]);

        return $code < 0x20 || $code > 0x7E ? sprintf('\\%03o', $code) : $byte;
    }
}
