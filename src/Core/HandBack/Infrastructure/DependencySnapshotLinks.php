<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Infrastructure;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

// A snapshot outlives the install it was copied from, so every link in it must resolve inside the snapshot alone.
final readonly class DependencySnapshotLinks
{
    public function __construct(private Filesystem $filesystem)
    {
    }

    // Each link is resolved by the kernel in the install and rewritten to that resolved path, because a target read as text can climb out through another link.
    public function keepInside(string $copy, string $install): void
    {
        $root = realpath($install);

        if (false === $root) {
            throw new IOException(sprintf('the install %s cannot be resolved', $install), path: $install);
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($copy, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            assert($entry instanceof SplFileInfo);

            if ($entry->isLink()) {
                $this->keepLinkInside($entry, substr($entry->getPathname(), strlen($copy) + 1), $root);
            }
        }
    }

    private function keepLinkInside(SplFileInfo $link, string $own, string $root): void
    {
        $resolved = realpath($root.'/'.$own);

        if (false === $resolved || ($resolved !== $root && !str_starts_with($resolved, $root.'/'))) {
            throw new IOException(sprintf('the link %s points outside the install or nowhere and cannot be kept in a snapshot', $own), path: $link->getPathname());
        }

        $target = $this->relative(dirname($own), $resolved === $root ? '' : substr($resolved, strlen($root) + 1));

        if ($target !== $link->getLinkTarget()) {
            $this->filesystem->remove($link->getPathname());
            $this->filesystem->symlink($target, $link->getPathname());
        }
    }

    // Both paths hold only real folders, so climbing with .. from the link's folder means the same in the install and the snapshot.
    private function relative(string $folder, string $path): string
    {
        $from = '.' === $folder ? [] : explode('/', $folder);
        $to = '' === $path ? [] : explode('/', $path);

        while ([] !== $from && [] !== $to && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        $parts = [...array_fill(0, count($from), '..'), ...$to];

        return [] === $parts ? '.' : implode('/', $parts);
    }
}
