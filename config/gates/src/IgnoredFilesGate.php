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

    /**
     * @return list<string>
     */
    private function ruleFilesDifferingFromTheIndex(Workspace $workspace): array
    {
        // Because ls-files reads the working-tree rules, an unstaged edit could hide a tracked file that CI, judging the committed rules, rejects; blob ids, not stat data, so an edit that keeps size and mtime still counts.
        $staged = $this->stagedRuleFiles($workspace);
        $present = array_filter(array_keys($staged), static fn (string $path): bool => is_file($workspace->root.'/'.$path) && !is_link($workspace->root.'/'.$path));
        $matching = $this->filesMatchingTheirBlobs($workspace, array_filter(array_intersect_key($staged, array_flip($present)), static fn (?string $blob): bool => null !== $blob));

        return array_values(array_filter(
            array_keys($staged),
            static fn (string $path): bool => null === $staged[$path] ? in_array($path, $present, true) : !in_array($path, $matching, true),
        ));
    }

    /**
     * @return array<string, ?string>
     */
    private function stagedRuleFiles(Workspace $workspace): array
    {
        // Because git reads no .gitignore that is a symbolic link or a submodule (gitlink) in CI, such an entry stages no rules (null), yet a regular file put in its place locally is read as rules; only stage 0, so a conflicted file is not compared three times.
        $result = $workspace->git(['ls-files', '-s', '-z']);

        if (!$result->succeeded()) {
            throw new ScopeFailure('git ls-files failed: '.$result->firstErrorLine());
        }

        $staged = [];
        foreach (explode("\0", $result->output) as $entry) {
            if (1 === preg_match('/\A(?<mode>\d+) (?<blob>[0-9a-f]+) 0\t(?<path>(?:.*\/)?\.gitignore)\z/s', $entry, $match)) {
                $staged[$match['path']] = in_array($match['mode'], ['120000', '160000'], true) ? null : $match['blob'];
            }
        }

        return $staged;
    }

    /**
     * @param array<string, string> $blobs
     *
     * @return list<string>
     */
    private function filesMatchingTheirBlobs(Workspace $workspace, array $blobs): array
    {
        // Because a file with a working-tree-encoding is checked out in that charset, its bytes matching the UTF-8 blob are not the rules CI reads.
        $paths = array_keys($blobs);

        if ([] === $paths) {
            return [];
        }

        $hashes = $this->workingTreeBlobs($workspace, $paths);
        $encodings = $this->stagedWorkingTreeEncodings($workspace, $paths);

        return array_values(array_filter(
            $paths,
            fn (string $path): bool => (!isset($encodings[$path]) && $hashes[$path] === $blobs[$path])
                || $this->sameRulesAfterCheckoutConversion($workspace, $blobs[$path], $this->workingTreeRules($workspace, $path, $encodings[$path] ?? null)),
        ));
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, string>
     */
    private function workingTreeBlobs(Workspace $workspace, array $paths): array
    {
        $result = $workspace->git(['hash-object', '--', ...$paths]);

        if (!$result->succeeded()) {
            throw new ScopeFailure('git hash-object failed: '.$result->firstErrorLine());
        }

        return array_combine($paths, explode("\n", rtrim($result->output, "\n")));
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, string>
     */
    private function stagedWorkingTreeEncodings(Workspace $workspace, array $paths): array
    {
        // Because check-attr only reports and runs no filter, and --cached reads the staged attributes (those CI checks out with) past the hardened empty attribute source; \G, so each record starts where the last ended, since a path holds no NUL.
        $result = $workspace->git(['check-attr', '--cached', '-z', 'working-tree-encoding', '--', ...$paths]);

        if (!$result->succeeded()) {
            throw new ScopeFailure('git check-attr failed: '.$result->firstErrorLine());
        }

        preg_match_all('/\G([^\0]*)\0working-tree-encoding\0([^\0]*)\0/', $result->output, $records);

        // Because git converts nothing for an empty value or for UTF-8 under any spelling it matches (case ignored, dash optional), and keeps the bytes as they are.
        return array_combine($records[1], $records[2])
            |> (static fn (array $values): array => array_filter($values, static fn (string $value): bool => !in_array($value, ['unspecified', 'unset', 'set', ''], true) && 1 !== preg_match('/\Autf-?8\z/i', $value)));
    }

    // Because a file git could not read, or one not valid in its charset, holds no rules CI would read.
    private function workingTreeRules(Workspace $workspace, string $path, ?string $encoding): ?string
    {
        $text = file_get_contents($workspace->root.'/'.$path);

        if (false === $text) {
            return null;
        }

        return null === $encoding ? $text : $this->decoded($text, $encoding);
    }

    // Because the hardened call hashes without the repository's attributes (a clean filter it names would run), a checkout that working-tree-encoding, eol=crlf, core.autocrlf or ident converted hashes differently from its clean blob; the working tree is decoded and both sides are cleaned as git cleans those conversions.
    private function sameRulesAfterCheckoutConversion(Workspace $workspace, string $blob, ?string $workingTreeRules): bool
    {
        $result = $workspace->git(['cat-file', 'blob', $blob]);

        if (!$result->succeeded()) {
            throw new ScopeFailure('git cat-file failed: '.$result->firstErrorLine());
        }

        return null !== $workingTreeRules && $this->cleaned($workingTreeRules) === $this->cleaned($result->output);
    }

    // Because git stores the file as UTF-8 and checks it out in the named charset with iconv, writing UTF-16LE-BOM (its own name, matched without case and with or without the dash) as a byte order mark and UTF-16LE; an unknown charset, an invalid byte, a missing mark or a decode that does not encode back to the same bytes leaves the file differing, since git reads the raw bytes as rules.
    private function decoded(string $text, string $encoding): ?string
    {
        [$charset, $mark] = 1 === preg_match('/\Autf-?16le-bom\z/i', $encoding) ? ['UTF-16LE', "\xFF\xFE"] : [$encoding, ''];

        if (!str_starts_with($text, $mark)) {
            return null;
        }

        // Warnings silenced, because iconv reports an unknown charset or an invalid byte both as a warning and as false.
        set_error_handler(static fn (): bool => true);

        try {
            $decoded = iconv($charset, 'UTF-8', substr($text, strlen($mark)));
            $lossless = false !== $decoded && $mark.iconv('UTF-8', $charset, $decoded) === $text;
        } finally {
            restore_error_handler();
        }

        return $lossless && false !== $decoded ? $decoded : null;
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
