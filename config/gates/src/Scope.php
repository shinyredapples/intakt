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

    private const array EXTENSIONLESS_PHP_FILES = ['bin/check'];

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
        $changed = $this->gitLines(['diff', '--name-only', '-z', $mergeBase]);
        $untracked = $this->gitLines(['ls-files', '-z', '--others', '--exclude-standard']);

        return array_values(array_unique(array_merge($changed, $untracked)));
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
    public function repositoryFiles(): array
    {
        if (!file_exists($this->root.'/.git')) {
            return $this->walkDirectory();
        }

        return $this->gitLines(['ls-files', '-z', '--cached', '--others', '--exclude-standard']);
    }

    /**
     * @return list<string>
     */
    private function existingFiles(): array
    {
        return $this->repositoryFiles();
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
        $result = $this->runner->run(['git', ...$arguments], $this->root);

        if (!$result->succeeded()) {
            throw new ScopeFailure('git '.$arguments[0].' failed: '.$result->firstErrorLine());
        }

        return array_values(array_filter(explode("\0", $result->output), static fn (string $path): bool => '' !== $path));
    }
}
