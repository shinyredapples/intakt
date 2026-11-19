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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

// Written by the orchestrator's user alone; a job reaches a snapshot only as a read-only mount, because jobs run under that same user id and could otherwise give themselves write permission back.
final readonly class DependencySnapshots
{
    private const string INCOMING = '.incoming';

    // The order of use, least recent first, kept as a list rather than in timestamps so two uses within one clock tick still have an order.
    private const string USES = '.uses';

    private const string LOCK = 'intakt-dependency-snapshots';

    private const string PIN = 'intakt-dependency-snapshot-';

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
            $this->evictPastCount($key);
        } finally {
            $lock->release();
        }
    }

    // Busy pins count as a miss rather than a wait, because waiting here would hold the store lock while another job runs.
    #[NoDiscard]
    public function leaseOf(DependencySnapshotKey $key, string $containerPath): ?DependencySnapshotLease
    {
        $lock = $this->locks->createLock(self::LOCK);
        $lock->acquire(true);

        try {
            $pin = $this->pinOf($key->value);

            if (!is_dir($this->pathOf($key)) || !$pin->acquireRead(false)) {
                return null;
            }

            $this->markUsed($key);

            return new DependencySnapshotLease(new JobInput($this->pathOf($key), $containerPath), $pin);
        } finally {
            $lock->release();
        }
    }

    private function pathOf(DependencySnapshotKey $key): string
    {
        return $this->root.'/'.$key->value;
    }

    // Without an expiry, because a check may mount its snapshot for longer than any fixed time.
    private function pinOf(string $name): SharedLockInterface
    {
        return $this->locks->createLock(self::PIN.$name, null);
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
        new Filesystem()->rename($incoming, $snapshot);
    }

    // Links are left alone, because chmod follows a link and would change whatever it points at.
    private function makeReadOnly(string $snapshot): void
    {
        foreach ($this->treeOf($snapshot) as $entry) {
            if (!$entry->isLink()) {
                chmod($entry->getPathname(), $entry->isDir() || 0 !== ($entry->getPerms() & 0o100) ? 0o555 : 0o444);
            }
        }

        chmod($snapshot, 0o555);
    }

    private function markUsed(DependencySnapshotKey $key): void
    {
        $this->writeUses([...array_diff($this->uses(), [$key->value]), $key->value]);
    }

    // A pinned snapshot is skipped and a less recently used one goes instead, so the store stays bounded while a job still mounts the old one; the snapshot just saved always stays.
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

    private function evicted(string $name): bool
    {
        $pin = $this->pinOf($name);

        if (!$pin->acquire(false)) {
            return false;
        }

        try {
            $this->remove($this->root.'/'.$name);

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

    // Its folders are read-only, so they are opened for the owner first; their files need no write permission to be removed.
    private function remove(string $snapshot): void
    {
        if (is_link($snapshot) || !is_dir($snapshot)) {
            return;
        }

        chmod($snapshot, 0o755);

        foreach ($this->treeOf($snapshot) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                chmod($entry->getPathname(), 0o755);
            }
        }

        new Filesystem()->remove($snapshot);
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
