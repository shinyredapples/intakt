<?php

declare(strict_types=1);

namespace Intakt\Gates;

use ValueError;

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

    /**
     * @return list<string>
     */
    private function ruleFilesDifferingFromTheIndex(Workspace $workspace): array
    {
        // Because ls-files reads the working-tree rules, an unstaged edit could hide a tracked file that CI, judging the committed rules, rejects; blob ids, not stat data, so an edit that keeps size and mtime still counts.
        $staged = $this->stagedRuleFiles($workspace);
        $present = array_filter(array_keys($staged), static fn (string $path): bool => is_file($workspace->root.'/'.$path) && !is_link($workspace->root.'/'.$path));
        $hashes = $this->workingTreeBlobs($workspace, array_values($present));

        return array_keys($staged)
            |> (static fn (array $paths): array => array_filter($paths, static fn (string $path): bool => ($hashes[$path] ?? null) !== $staged[$path]))
            |> (fn (array $paths): array => array_filter($paths, fn (string $path): bool => !isset($hashes[$path]) || !$this->sameRulesAfterCheckoutConversion($workspace, $path, $staged[$path])))
            |> array_values(...);
    }

    /**
     * @return array<string, string>
     */
    private function stagedRuleFiles(Workspace $workspace): array
    {
        // Because git reads no .gitignore that is a symbolic link or a submodule (gitlink), neither here nor in CI, such an entry carries no rules to compare; only stage 0, so a conflicted file is not compared three times.
        $result = $workspace->git(['ls-files', '-s', '-z']);

        if (!$result->succeeded()) {
            throw new ScopeFailure('git ls-files failed: '.$result->firstErrorLine());
        }

        $staged = [];
        foreach (explode("\0", $result->output) as $entry) {
            if (1 === preg_match('/\A(?<mode>\d+) (?<blob>[0-9a-f]+) 0\t(?<path>(?:.*\/)?\.gitignore)\z/s', $entry, $match) && !in_array($match['mode'], ['120000', '160000'], true)) {
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

    // Because the hardened call hashes without the repository's attributes (a clean filter it names would run), a checkout that working-tree-encoding, eol=crlf, core.autocrlf or ident converted hashes differently from its clean blob; the working tree is decoded and both sides are cleaned as git cleans those conversions.
    private function sameRulesAfterCheckoutConversion(Workspace $workspace, string $path, string $blob): bool
    {
        $result = $workspace->git(['cat-file', 'blob', $blob]);

        if (!$result->succeeded()) {
            throw new ScopeFailure('git cat-file failed: '.$result->firstErrorLine());
        }

        $workingTree = file_get_contents($workspace->root.'/'.$path);
        $decoded = false === $workingTree ? null : $this->decodedFromItsWorkingTreeEncoding($workspace, $path, $workingTree);

        return null !== $decoded && $this->cleaned($decoded) === $this->cleaned($result->output);
    }

    // Because git stores a file whose working-tree-encoding names a charset as UTF-8 and checks it out in that charset; check-attr only reports and runs no filter, --cached reads the staged attributes (those CI checks out with) past the hardened empty attribute source, and a charset PHP cannot decode leaves the file differing rather than guessed.
    private function decodedFromItsWorkingTreeEncoding(Workspace $workspace, string $path, string $text): ?string
    {
        $result = $workspace->git(['check-attr', '--cached', '-z', 'working-tree-encoding', '--', $path]);

        if (!$result->succeeded()) {
            throw new ScopeFailure('git check-attr failed: '.$result->firstErrorLine());
        }

        $encoding = explode("\0", $result->output)[2] ?? 'unspecified';

        if (in_array($encoding, ['unspecified', 'unset', 'set'], true)) {
            return $text;
        }

        try {
            return mb_convert_encoding($text, 'UTF-8', $encoding);
        } catch (ValueError) {
            return null;
        }
    }

    // Because git drops the carriage return before each line break of a .gitignore and its ident clean conversion collapses the `$Id: <object id> $` it wrote to `$Id$`, neither changes the rules; any other `$Id: ... $` text is a rule edit.
    private function cleaned(string $rules): string
    {
        return str_replace("\r\n", "\n", $rules)
            |> (static fn (string $text): string => (string) preg_replace('/\$Id: (?:[0-9a-f]{40}|[0-9a-f]{64}) \$/', '$Id$', $text));
    }

    // Because the full report must name a file the reader can untrack, a path JSON would mangle (not UTF-8), a line break or control would split or hide (C0, DEL, C1 such as U+0085, U+2028, U+2029) or a format character would reorder (U+202E) is quoted as git quotes it; \z, not $, so a trailing newline counts.
    private function shown(string $path): string
    {
        $plain = 1 === preg_match('/\A[^\p{Cc}\p{Cf}\p{Zl}\p{Zp}]*\z/u', $path) && !str_starts_with($path, '"');

        return $plain ? $path : '"'.addcslashes($path, "\0..\37\"\\\177..\377").'"';
    }
}
