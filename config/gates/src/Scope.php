<?php

declare(strict_types=1);

namespace Intakt\Gates;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class Scope
{
    public const array EXCLUDED_PREFIXES = [
        'upstream/',
        'var/',
        'vendor/',
        'node_modules/',
        'tests/Gates/Fixtures/',
    ];

    private const array EXTENSIONLESS_PHP_FILES = ['bin/check', 'bin/instance', 'bin/probe'];

    private const int PATHS_PER_CALL = 500;

    // Only the committed .gitignore files, because --exclude-standard also reads info/exclude and core.excludesFile, which the repository sets without a diff showing it.
    private const string GITIGNORE_ONLY = '--exclude-per-directory=.gitignore';

    public function __construct(
        private string $root,
        private ProcessRunner $runner,
    ) {
    }

    /**
     * @return list<string>
     */
    public function allPhpFiles(): array
    {
        return $this->inScope($this->existingFiles());
    }

    /**
     * @return list<string>
     */
    public function changedPhpFiles(string $mergeBase): array
    {
        return $this->inScope($this->changedPaths($mergeBase));
    }

    /**
     * @return list<string>
     */
    public function changedPaths(string $mergeBase): array
    {
        $staged = $this->gitLines(['diff-index', '--cached', '--name-only', '-z', $mergeBase]);

        return array_values(array_unique([...$staged, ...$this->unstagedPaths(), ...$this->untrackedPaths()]));
    }

    /**
     * @return list<string>
     */
    public function untrackedPaths(): array
    {
        return $this->gitLines(['ls-files', '-z', '--others', self::GITIGNORE_ONLY]);
    }

    public function includes(string $path): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return str_ends_with($path, '.php') || in_array($path, self::EXTENSIONLESS_PHP_FILES, true);
    }

    /**
     * @return list<string>
     */
    public function repositoryFiles(): array
    {
        if (!file_exists($this->root.'/.git')) {
            return $this->walkDirectory();
        }

        return $this->gitLines(['ls-files', '-z', '--cached', '--others', self::GITIGNORE_ONLY]);
    }

    /**
     * @return list<string>
     */
    private function existingFiles(): array
    {
        return $this->repositoryFiles();
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function inScope(array $paths): array
    {
        $inScope = array_filter(array_unique($paths), fn (string $path): bool => $this->includes($path) && is_file($this->root.'/'.$path));
        sort($inScope);

        return $inScope;
    }

    /**
     * @return list<string>
     */
    private function walkDirectory(): array
    {
        $files = [];
        $walker = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($walker as $file) {
            if ($file instanceof SplFileInfo) {
                $files[] = substr($file->getPathname(), strlen($this->root) + 1);
            }
        }

        return array_values(array_filter($files, static fn (string $path): bool => !str_starts_with($path, 'vendor/')));
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    private function gitLines(array $arguments): array
    {
        $result = $this->runner->git($arguments, $this->root);

        if (!$result->succeeded()) {
            throw new ScopeFailure('git '.$arguments[0].' failed: '.$result->firstErrorLine());
        }

        return array_values(array_filter(explode("\0", $result->output), static fn (string $path): bool => '' !== $path));
    }

    /**
     * @return list<string>
     */
    private function unstagedPaths(): array
    {
        // Git would compare the working tree through the clean filters an info/attributes written after its check names, so the files are hashed as they are.
        $entries = $this->indexEntries();
        $files = array_values(array_filter($entries, fn (array $entry): bool => is_file($this->root.'/'.$entry[0]) && !is_link($this->root.'/'.$entry[0])));
        $blobs = $this->unfilteredBlobsOf(array_column($files, 0));
        $unchanged = [];

        foreach ($files as $index => [$path, $blob, $mode]) {
            if (($blobs[$index] ?? '') === $blob && $this->modeOf($path) === $mode) {
                $unchanged[] = $path;
            }
        }

        return array_values(array_diff(array_column($entries, 0), $unchanged));
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function indexEntries(): array
    {
        $entries = [];

        foreach ($this->gitLines(['ls-files', '-z', '--stage']) as $entry) {
            // A conflicted path, a link or a submodule never matches, so it always counts as changed.
            $regular = 1 === preg_match('/^(100644|100755) ([0-9a-f]+) 0\t(.*)$/s', $entry, $match);
            $entries[] = $regular ? [$match[3], $match[2], $match[1]] : [(string) preg_replace('/^[^\t]*\t/', '', $entry), '', ''];
        }

        return $entries;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function unfilteredBlobsOf(array $paths): array
    {
        $blobs = [];

        // In batches, because the paths are arguments and the system limits their total length.
        foreach (array_chunk($paths, self::PATHS_PER_CALL) as $batch) {
            $result = $this->runner->git(['hash-object', '--no-filters', '--', ...$batch], $this->root);

            if (!$result->succeeded()) {
                throw new ScopeFailure('git hash-object failed: '.$result->firstErrorLine());
            }

            $blobs = [...$blobs, ...explode("\n", trim($result->output))];
        }

        return $blobs;
    }

    private function modeOf(string $path): string
    {
        // Git records only the owner's execute bit, so that bit alone decides the mode it would see.
        return 0 !== ((int) fileperms($this->root.'/'.$path) & 0o100) ? '100755' : '100644';
    }
}
