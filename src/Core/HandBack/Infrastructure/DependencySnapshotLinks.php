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

    // A link into the install is rebased to a relative one; a link out of it cannot be kept and refuses the save.
    public function keepInside(string $copy, string $install): void
    {
        $roots = array_values(array_unique(array_filter([rtrim($install, '/'), (string) realpath($install)], static fn (string $root): bool => '' !== $root)));

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($copy, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            assert($entry instanceof SplFileInfo);

            if ($entry->isLink()) {
                $this->keepLinkInside($entry, $copy, $roots);
            }
        }
    }

    /**
     * @param list<string> $roots
     */
    private function keepLinkInside(SplFileInfo $link, string $copy, array $roots): void
    {
        $target = $link->getLinkTarget();
        $own = substr($link->getPathname(), strlen($copy) + 1);
        $inside = str_starts_with($target, '/') ? $this->withinRoots($target, $roots) : $this->normalized(dirname($own).'/'.$target);

        if (null === $inside) {
            throw new IOException(sprintf('the link %s points outside the install and cannot be kept in a snapshot', $own), path: $link->getPathname());
        }

        if (str_starts_with($target, '/')) {
            $this->filesystem->remove($link->getPathname());
            $this->filesystem->symlink($this->relative(dirname($own), $inside), $link->getPathname());
        }
    }

    /**
     * @param list<string> $roots
     */
    private function withinRoots(string $target, array $roots): ?string
    {
        foreach ($roots as $root) {
            if (str_starts_with($target, $root.'/')) {
                return $this->normalized(substr($target, strlen($root) + 1));
            }
        }

        return null;
    }

    // Null because a path that climbs above where it starts leaves the install, which the caller must refuse.
    private function normalized(string $path): ?string
    {
        $parts = [];

        foreach (array_filter(explode('/', $path), static fn (string $part): bool => '' !== $part && '.' !== $part) as $part) {
            if ('..' !== $part) {
                $parts[] = $part;

                continue;
            }

            if ([] === $parts) {
                return null;
            }

            array_pop($parts);
        }

        return implode('/', $parts);
    }

    private function relative(string $folder, string $path): string
    {
        $from = '.' === $folder ? [] : explode('/', $folder);
        $to = explode('/', $path);

        while ([] !== $from && count($to) > 1 && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return implode('/', [...array_fill(0, count($from), '..'), ...$to]);
    }
}
