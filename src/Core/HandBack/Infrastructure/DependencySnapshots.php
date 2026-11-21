<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Infrastructure;

use FilesystemIterator;
use Intakt\Core\HandBack\Contract\HandBackServices;
use Intakt\Core\HandBack\Domain\DependencySnapshotKey;
use Intakt\Core\Job\Contract\JobInput;
use NoDiscard;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\FlockStore;

// Written by the orchestrator's user alone; a job reaches a snapshot only as a read-only mount, because jobs run under that same user id and could otherwise give themselves write permission back.
final readonly class DependencySnapshots
{
    private const string INCOMING = '.incoming';

    // Not a valid key, so a snapshot an eviction stopped half-way through deleting is never leased: under its key a snapshot is either complete or absent.
    private const string EVICTING = '.evicting-';

    // The order of use, least recent first, kept as a list rather than in timestamps so two uses within one clock tick still have an order.
    private const string USES = '.uses';

    // One folder per pinned key, so an eviction removes the pin's file by its folder and never depends on how the lock library names its files.
    private const string PINS = '.pins';

    private const string LOCK = 'intakt-dependency-snapshots';

    private const string PIN = 'pin';

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/dependency-snapshots')]
        private string $root,
        #[Autowire(param: HandBackServices::DEPENDENCY_SNAPSHOTS)]
        private int $count,
        private LockFactory $locks,
    ) {
        if ($count < 1) {
            throw DependencySnapshotCountRefused::belowOne($count);
        }
    }

    // A key that is already stored keeps its first snapshot, because the same lock inputs pin the same install.
    public function save(DependencySnapshotKey $key, string $install): void
    {
        $lock = $this->locks->createLock(self::LOCK);
        $lock->acquire(true);

        try {
            $this->prepareRoot();

            if (!is_dir($this->pathOf($key))) {
                $this->copyReadOnly($install, $this->pathOf($key));
            }

            $this->markUsed($key);
            $this->trim($key);
        } finally {
            $lock->release();
        }
    }

    // Busy pins count as a miss rather than a wait, because waiting here would hold the store lock while another job runs. A lease trims the store too, so hits alone bring it back to the count once older leases end.
    #[NoDiscard]
    public function leaseOf(DependencySnapshotKey $key, string $containerPath): ?DependencySnapshotLease
    {
        $lock = $this->locks->createLock(self::LOCK);
        $lock->acquire(true);

        try {
            if (!is_dir($this->root)) {
                return null;
            }

            $lease = $this->pinned($key, $containerPath);
            $this->trim($key);

            return $lease;
        } finally {
            $lock->release();
        }
    }

    // The folder is checked before the pin is created, so a key that was evicted, and its pin file with it, never gets a pin back without a snapshot.
    private function pinned(DependencySnapshotKey $key, string $containerPath): ?DependencySnapshotLease
    {
        if (!is_dir($this->pathOf($key))) {
            return null;
        }

        $pin = $this->pinOf($key->value);

        if (!$pin->acquireRead(false)) {
            return null;
        }

        $this->markUsed($key);

        return new DependencySnapshotLease(new JobInput($this->pathOf($key), $containerPath), $pin);
    }

    private function pathOf(DependencySnapshotKey $key): string
    {
        return $this->root.'/'.$key->value;
    }

    // Without an expiry, because a check may mount its snapshot for longer than any fixed time.
    private function pinOf(string $name): SharedLockInterface
    {
        $folder = $this->root.'/'.self::PINS.'/'.$name;
        new Filesystem()->mkdir($folder, 0o700);

        return new LockFactory(new FlockStore($folder))->createLock(self::PIN, null);
    }

    // An incoming copy left by a crashed save is removed here, under the lock, so no other save is still writing it.
    private function prepareRoot(): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->root, 0o755);
        $filesystem->chmod($this->root, 0o755);
        $this->remove($this->root.'/'.self::INCOMING);
    }

    // Copied under another name and renamed at the end, so a snapshot is either whole or absent.
    private function copyReadOnly(string $install, string $snapshot): void
    {
        $incoming = $this->root.'/'.self::INCOMING;
        new Filesystem()->mirror($install, $incoming);
        $this->makeReadOnly($incoming);
        $this->moveWhole($incoming, $snapshot);
    }

    // PHP's own rename, because Filesystem::rename() falls back to a copy and a delete when it fails, and a fallback stopped part way would leave a partial snapshot under a valid key.
    private function moveWhole(string $from, string $to): void
    {
        if (!rename($from, $to)) {
            throw new IOException(sprintf('Failed to rename "%s" to "%s".', $from, $to), path: $from);
        }
    }

    // Links are left alone, because chmod follows a link and would change whatever it points at. The first failure stops the save, so a snapshot that is not read-only everywhere never gets its key.
    private function makeReadOnly(string $snapshot): void
    {
        foreach ($this->treeOf($snapshot) as $entry) {
            if (!$entry->isLink()) {
                $this->changeMode($entry->getPathname(), $entry->isDir() || 0 !== ($entry->getPerms() & 0o100) ? 0o555 : 0o444);
            }
        }

        $this->changeMode($snapshot, 0o555);
    }

    private function changeMode(string $path, int $mode): void
    {
        if (!chmod($path, $mode)) {
            throw new IOException(sprintf('Failed to change the mode of "%s".', $path), path: $path);
        }
    }

    private function markUsed(DependencySnapshotKey $key): void
    {
        $this->writeUses([...array_diff($this->uses(), [$key->value]), $key->value]);
    }

    // Runs under the store lock, so no other eviction is still deleting a leftover it removes, and no lease can take a pin it sweeps.
    private function trim(DependencySnapshotKey $kept): void
    {
        $this->removeEvictionLeftovers();
        $this->removeInterruptedUseWrites();
        $this->evictPastCount($kept);
        $this->sweepOrphanPins();
    }

    // Filesystem::dumpFile() writes the order to a temporary `.uses<random>` file beside it and renames it, so a crash before that rename leaves the temporary file behind.
    private function removeInterruptedUseWrites(): void
    {
        foreach ($this->entriesOf($this->root) as $name) {
            $path = $this->root.'/'.$name;

            if (self::USES !== $name && str_starts_with($name, self::USES) && is_file($path) && !is_link($path)) {
                new Filesystem()->remove($path);
            }
        }
    }

    private function removeEvictionLeftovers(): void
    {
        foreach ($this->entriesOf($this->root) as $name) {
            if (str_starts_with($name, self::EVICTING)) {
                $this->remove($this->root.'/'.$name);
            }
        }
    }

    // A pin outlives its snapshot only when an eviction stopped between the two removals; a lease never holds such a pin, because it pins only a snapshot that is present, so a busy one is left for a later trim.
    private function sweepOrphanPins(): void
    {
        foreach ($this->entriesOf($this->root.'/'.self::PINS) as $name) {
            if (is_dir($this->root.'/'.self::PINS.'/'.$name) && !is_dir($this->root.'/'.$name)) {
                $this->removePinOf($name);
            }
        }
    }

    private function removePinOf(string $name): void
    {
        $pin = $this->pinOf($name);

        if (!$pin->acquire(false)) {
            return;
        }

        try {
            new Filesystem()->remove($this->root.'/'.self::PINS.'/'.$name);
        } finally {
            $pin->release();
        }
    }

    /**
     * @return list<string>
     */
    private function entriesOf(string $directory): array
    {
        $entries = is_dir($directory) ? scandir($directory) : false;

        return false === $entries ? [] : array_values(array_diff($entries, ['.', '..']));
    }

    // A pinned snapshot is skipped and a less recently used one goes instead, so the store stays bounded while a job still mounts the old one; the snapshot just saved or leased always stays.
    private function evictPastCount(DependencySnapshotKey $saved): void
    {
        $snapshots = $this->leastRecentlyUsedFirst();
        $excess = count($snapshots) - $this->count;

        foreach ($snapshots as $name) {
            if ($excess > 0 && $name !== $saved->value && $this->evicted($name)) {
                --$excess;
            }
        }

        $this->writeUses(array_filter($this->uses(), fn (string $name): bool => is_dir($this->root.'/'.$name)));
    }

    // The pin file goes while its exclusive pin is held, so no lease holds it, and none can take it later: a lease checks the snapshot's folder first, under the same store lock. A rename that fails throws before anything else moves, so the snapshot stays whole under its key with its pin folder.
    private function evicted(string $name): bool
    {
        $pin = $this->pinOf($name);

        if (!$pin->acquire(false)) {
            return false;
        }

        try {
            $evicting = $this->root.'/'.self::EVICTING.$name;
            $this->moveWhole($this->root.'/'.$name, $evicting);
            $this->remove($evicting);
            new Filesystem()->remove($this->root.'/'.self::PINS.'/'.$name);

            return true;
        } finally {
            $pin->release();
        }
    }

    // Snapshots the order does not name yet come first, because nothing records that they were used.
    /**
     * @return list<string>
     */
    private function leastRecentlyUsedFirst(): array
    {
        $entries = scandir($this->root);
        $stored = array_filter(false === $entries ? [] : $entries, DependencySnapshotKey::isValue(...));
        $uses = $this->uses();
        $unrecorded = array_diff($stored, $uses);
        sort($unrecorded, SORT_STRING);

        return [...$unrecorded, ...array_values(array_intersect($uses, $stored))];
    }

    /**
     * @return list<string>
     */
    private function uses(): array
    {
        $file = $this->root.'/'.self::USES;
        $uses = is_file($file) ? json_decode(new Filesystem()->readFile($file), true) : [];

        return is_array($uses) ? array_values(array_filter($uses, static fn (mixed $name): bool => is_string($name) && DependencySnapshotKey::isValue($name))) : [];
    }

    /**
     * @param array<string> $uses
     */
    private function writeUses(array $uses): void
    {
        new Filesystem()->dumpFile($this->root.'/'.self::USES, json_encode(array_values($uses), JSON_THROW_ON_ERROR));
    }

    // Its folders are read-only, so they are opened for the owner first; their files need no write permission to be removed. The folder's entries are removed and the folder itself last, because Filesystem::remove() first moves a folder to a random name beside it, and a removal stopped there would leave a copy under a name no later trim looks for: inside the folder, any such move stays under the name this store reclaims.
    private function remove(string $folder): void
    {
        if (is_link($folder) || !is_dir($folder)) {
            return;
        }

        chmod($folder, 0o755);

        foreach ($this->treeOf($folder) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                chmod($entry->getPathname(), 0o755);
            }
        }

        new Filesystem()->remove(array_map(static fn (string $name): string => $folder.'/'.$name, $this->entriesOf($folder)));

        if (!rmdir($folder)) {
            throw new IOException(sprintf('Failed to remove the folder "%s".', $folder), path: $folder);
        }
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function treeOf(string $directory): iterable
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
    }
}
