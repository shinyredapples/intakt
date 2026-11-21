<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Infrastructure;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Filesystem\Exception\IOException;

// Fails closed, so a snapshot under its key is always whole and read-only: each call is checked and the first failure throws before anything else moves.
final readonly class DependencySnapshotFiles
{
    // Links are left alone, because chmod follows a link and would change whatever it points at.
    public function makeReadOnly(string $folder): void
    {
        foreach ($this->treeOf($folder) as $entry) {
            if (!$entry->isLink()) {
                $this->changeMode($entry->getPathname(), $entry->isDir() || 0 !== ($entry->getPerms() & 0o100) ? 0o555 : 0o444);
            }
        }

        $this->changeMode($folder, 0o555);
    }

    // PHP's own rename, because Filesystem::rename() falls back to a copy and a delete when it fails, and a fallback stopped part way would leave a partial snapshot under a valid key.
    public function moveWhole(string $from, string $to): void
    {
        if (!rename($from, $to)) {
            throw new IOException(sprintf('Failed to rename "%s" to "%s".', $from, $to), path: $from);
        }
    }

    /**
     * @return iterable<SplFileInfo>
     */
    public function treeOf(string $folder): iterable
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
    }

    private function changeMode(string $path, int $mode): void
    {
        if (!chmod($path, $mode)) {
            throw new IOException(sprintf('Failed to change the mode of "%s".', $path), path: $path);
        }
    }
}
