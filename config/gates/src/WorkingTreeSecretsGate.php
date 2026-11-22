<?php

declare(strict_types=1);

namespace Intakt\Gates;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class WorkingTreeSecretsGate extends GitleaksGate
{
    public function name(): string
    {
        return 'secrets';
    }

    public function run(Workspace $workspace): GateResult
    {
        $copy = sys_get_temp_dir().'/intakt-secrets-'.bin2hex(random_bytes(6));

        try {
            $this->copyRepositoryFilesTo($workspace, $copy);

            return $this->scanTree($workspace, $copy);
        } catch (ScopeFailure $failure) {
            return GateResult::failedBecause($this->name(), $failure->getMessage());
        } finally {
            $this->remove($copy);
        }
    }

    protected function bypassesIn(Workspace $workspace): array
    {
        return [...$this->ignoreFileFindings($workspace), ...$this->inlineAllowDirectivesIn($workspace), ...$this->trackedFilesGitleaksSkips($workspace)];
    }

    private function copyRepositoryFilesTo(Workspace $workspace, string $directory): void
    {
        foreach ($workspace->repositoryFiles() as $path) {
            $source = $workspace->root.'/'.$path;

            if (is_file($source) && !is_link($source)) {
                $this->ensureFolder(dirname($directory.'/'.$path));
                $this->copyFile($source, $directory.'/'.$path);
            }
        }

        $this->ensureFolder($directory);
    }

    private function scanTree(Workspace $workspace, string $source): GateResult
    {
        return $this->scan($workspace, new ScanTarget($source, ['--no-git']), $workspace->config('gitleaks.toml'));
    }

    private function ensureFolder(string $folder): void
    {
        if (!is_dir($folder) && !mkdir($folder, 0o777, true) && !is_dir($folder)) {
            throw new ScopeFailure('cannot create the folder '.$folder.' for the scan');
        }
    }

    private function copyFile(string $source, string $target): void
    {
        if (!copy($source, $target)) {
            throw new ScopeFailure('cannot copy '.$source.' for the scan');
        }
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($directory);
    }

    /**
     * @return list<string>
     */
    private function inlineAllowDirectivesIn(Workspace $workspace): array
    {
        try {
            $paths = $workspace->repositoryFiles();
        } catch (ScopeFailure $failure) {
            return ['cannot search for inline allow directives: '.$failure->getMessage()];
        }

        $carriers = array_filter($paths, fn (string $path): bool => $this->carriesInlineAllow($workspace->root.'/'.$path));

        return array_map(
            static fn (string $path): string => $path.' carries an inline allow directive that hides findings from the scan',
            array_values($carriers),
        );
    }

    private function carriesInlineAllow(string $file): bool
    {
        return is_file($file) && !is_link($file) && str_contains((string) file_get_contents($file), self::INLINE_ALLOW);
    }

    /**
     * @return list<string>
     */
    private function trackedFilesGitleaksSkips(Workspace $workspace): array
    {
        if (!$workspace->hasGit()) {
            return [];
        }

        $tracked = $workspace->gitWithPathspecMagic(['ls-files', '--cached', '--', ...self::UNSCANNED_FOLDERS]);

        if (!$tracked->succeeded()) {
            return ['cannot list tracked files: '.$tracked->firstErrorLine()];
        }

        return array_map(
            static fn (string $path): string => $path.' is tracked in a folder the scanner skips, remove it from the repository',
            $this->lines($tracked->output),
        );
    }
}
