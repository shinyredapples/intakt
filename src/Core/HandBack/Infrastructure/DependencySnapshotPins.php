<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Infrastructure;

use FilesystemIterator;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\FlockStore;

// A pin is a lock file inside the store, so it lives and goes with the store's own folder rather than the application's locks.
final readonly class DependencySnapshotPins
{
    private const string PIN = 'pin';

    public function __construct(private Filesystem $filesystem)
    {
    }

    public function of(string $store, string $name): SharedLockInterface
    {
        return new LockFactory(new FlockStore($this->folderOf($store, $name)))->createLock(self::PIN, ttl: null);
    }

    public function removeTheFolderOf(string $store, string $name): void
    {
        $this->filesystem->remove($this->folderOf($store, $name));
    }

    // Safe without a lease check, because a lease exists only for a snapshot folder that is present; a pin held anyway is skipped.
    public function removeTheOrphansIn(string $store): void
    {
        $pins = $store.'/'.DependencySnapshots::PINS;

        if (!is_dir($pins) || is_link($pins)) {
            return;
        }

        foreach (new FilesystemIterator($pins, FilesystemIterator::SKIP_DOTS) as $entry) {
            assert($entry instanceof SplFileInfo);
            $snapshot = $store.'/'.$entry->getFilename();

            if ($entry->isDir() && !$entry->isLink() && (!is_dir($snapshot) || is_link($snapshot))) {
                $this->removeTheFree($store, $entry->getFilename());
            }
        }
    }

    private function removeTheFree(string $store, string $name): void
    {
        $pin = $this->of($store, $name);

        if (!$pin->acquire(false)) {
            return;
        }

        try {
            $this->removeTheFolderOf($store, $name);
        } finally {
            $pin->release();
        }
    }

    private function folderOf(string $store, string $name): string
    {
        return $store.'/'.DependencySnapshots::PINS.'/'.$name;
    }
}
