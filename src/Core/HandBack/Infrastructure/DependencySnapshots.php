<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Infrastructure;

use FilesystemIterator;
use Intakt\Core\HandBack\Contract\HandBackServices;
use Intakt\Core\HandBack\Domain\DependencySnapshotKey;
use NoDiscard;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

// Only the orchestrator's own process writes here, because a later check trusts what it holds: build jobs get no mount of it, and a check gets one snapshot's folder as a read-only bind.
final readonly class DependencySnapshots
{
    public const string INCOMING = '.incoming';

    public const string PINS = '.pins';

    // Starts with a dot, so no folder under this name is ever a valid key that a lease would mount.
    public const string EVICTING = '.evicting-';

    private const string STORE_LOCK = 'intakt-dependency-snapshots';

    public function __construct(
        #[Autowire(param: HandBackServices::DEPENDENCY_SNAPSHOTS_DIRECTORY)]
        private string $directory,
        #[Autowire(param: HandBackServices::DEPENDENCY_SNAPSHOT_COUNT)]
        private int $count,
        private LockFactory $locks,
        private Filesystem $filesystem,
        private DependencySnapshotUses $uses,
        private DependencySnapshotLinks $links,
        private DependencySnapshotPins $pins,
    ) {
        if ($count < 1) {
            throw DependencySnapshotCountRefused::belowOne($count);
        }
    }

    public function save(DependencySnapshotKey $key, string $install): void
    {
        $lock = $this->locks->createLock(self::STORE_LOCK, ttl: null);
        $lock->acquire(true);

        try {
            $this->prepareTheStore();
            $this->removeTheLeftoverIncoming();

            if (!$this->holds($key->value)) {
                $this->copyReadOnly($install, $key);
            }

            $this->trimAllBut($key);
            $this->markUsed($key);
        } finally {
            $lock->release();
        }
    }

    // Null when the store holds no snapshot of the key, so the check installs itself.
    #[NoDiscard]
    public function lease(DependencySnapshotKey $key): ?DependencySnapshotLease
    {
        $lock = $this->locks->createLock(self::STORE_LOCK, ttl: null);
        $lock->acquire(true);

        try {
            $this->prepareTheStore();
            $lease = $this->pinned($key);
            $this->trimAllBut($key);

            if ($lease instanceof DependencySnapshotLease) {
                $this->markUsed($key);
            }

            return $lease;
        } finally {
            $lock->release();
        }
    }

    private function prepareTheStore(): void
    {
        $this->filesystem->mkdir($this->directory, 0o700);
        $this->changeMode($this->directory, 0o700);
    }

    private function removeTheLeftoverIncoming(): void
    {
        $incoming = $this->directory.'/'.self::INCOMING;

        if (!file_exists($incoming) && !is_link($incoming)) {
            return;
        }

        $this->removeSnapshotFolder($incoming);
    }

    private function holds(string $name): bool
    {
        $folder = $this->directory.'/'.$name;

        return is_dir($folder) && !is_link($folder);
    }

    // Published under its key by one rename, so no folder under a key ever holds a partial or writable copy.
    private function copyReadOnly(string $install, DependencySnapshotKey $key): void
    {
        $incoming = $this->directory.'/'.self::INCOMING;
        $this->filesystem->mirror($install, $incoming);
        $this->links->keepInside($incoming, $install);
        $this->makeReadOnly($incoming);

        if (!@rename($incoming, $this->directory.'/'.$key->value)) {
            throw new IOException(sprintf('the snapshot %s could not be published from %s', $key->value, self::INCOMING), path: $incoming);
        }
    }

    private function makeReadOnly(string $folder): void
    {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($entries as $entry) {
            assert($entry instanceof SplFileInfo);

            // A link is left alone, because chmod would change the file it points at.
            if (!$entry->isLink()) {
                $this->changeMode($entry->getPathname(), $this->readOnlyModeOf($entry));
            }
        }

        $this->changeMode($folder, 0o555);
    }

    private function readOnlyModeOf(SplFileInfo $entry): int
    {
        if ($entry->isDir() || 0 !== ($entry->getPerms() & 0o111)) {
            return 0o555;
        }

        return 0o444;
    }

    private function changeMode(string $path, int $mode): void
    {
        if (!@chmod($path, $mode)) {
            throw new IOException(sprintf('the mode of %s could not be set to %o', $path, $mode), path: $path);
        }
    }

    private function pinned(DependencySnapshotKey $key): ?DependencySnapshotLease
    {
        if (!$this->holds($key->value)) {
            return null;
        }

        $pin = $this->pins->of($this->directory, $key->value);

        // Tried without waiting, because only an eviction holds a pin exclusively, and that snapshot is on its way out.
        if (!$pin->acquireRead(false)) {
            return null;
        }

        return new DependencySnapshotLease($this->directory.'/'.$key->value, $pin);
    }

    // Also repairs what an interrupted eviction or use-order write left, because nothing else ever reclaims it.
    private function trimAllBut(DependencySnapshotKey $kept): void
    {
        $this->removeTheLeftoverEvictions();
        $this->pins->removeTheOrphansIn($this->directory);
        $this->uses->removeTheLeftoversIn($this->directory);
        $this->evictTheLeastRecentlyUsedPastTheCount($kept);
    }

    private function removeTheLeftoverEvictions(): void
    {
        foreach (new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            assert($entry instanceof SplFileInfo);

            if (str_starts_with($entry->getFilename(), self::EVICTING)) {
                $this->removeSnapshotFolder($entry->getPathname());
            }
        }
    }

    // Allows more than the count while snapshots are leased, because a leased snapshot is mounted and the one being saved or leased is kept.
    private function evictTheLeastRecentlyUsedPastTheCount(DependencySnapshotKey $kept): void
    {
        $snapshots = $this->snapshotsLeastRecentFirst();
        $over = count($snapshots) - $this->count;

        foreach ($snapshots as $name) {
            if ($over <= 0) {
                return;
            }

            if ($name !== $kept->value && $this->evicted($name)) {
                --$over;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function snapshotsLeastRecentFirst(): array
    {
        $present = [];

        foreach (new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            assert($entry instanceof SplFileInfo);
            $name = $entry->getFilename();

            if (1 === preg_match('/\A[0-9a-f]{64}\z/', $name) && $this->holds($name)) {
                $present[] = $name;
            }
        }

        sort($present, SORT_STRING);
        $used = array_values(array_intersect($this->uses->leastRecentFirstIn($this->directory), $present));

        return [...array_values(array_diff($present, $used)), ...$used];
    }

    // An eviction must take the pin exclusively, which fails while any check holds its shared pin.
    private function evicted(string $name): bool
    {
        $pin = $this->pins->of($this->directory, $name);

        if (!$pin->acquire(false)) {
            return false;
        }

        try {
            $this->removeSnapshotFolder($this->movedAside($name));
            $this->pins->removeTheFolderOf($this->directory, $name);
        } finally {
            $pin->release();
        }

        return true;
    }

    // Moved from under its key by one rename before any file goes, so a removal stopped half-way leaves no partial snapshot that a lease would mount.
    private function movedAside(string $name): string
    {
        $evicting = $this->directory.'/'.self::EVICTING.$name;

        if (!@rename($this->directory.'/'.$name, $evicting)) {
            throw new IOException(sprintf('the snapshot %s could not be moved aside for its eviction', $name), path: $this->directory.'/'.$name);
        }

        return $evicting;
    }

    private function removeSnapshotFolder(string $folder): void
    {
        if (is_dir($folder) && !is_link($folder)) {
            $this->changeMode($folder, 0o700);
            $folders = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

            foreach ($folders as $entry) {
                assert($entry instanceof SplFileInfo);

                if ($entry->isDir() && !$entry->isLink()) {
                    $this->changeMode($entry->getPathname(), 0o700);
                }
            }
        }

        $this->filesystem->remove($folder);
    }

    private function markUsed(DependencySnapshotKey $key): void
    {
        $present = $this->snapshotsLeastRecentFirst();
        $this->uses->record($this->directory, [...array_values(array_diff($present, [$key->value])), $key->value]);
    }
}
