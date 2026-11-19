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
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

// Written by the orchestrator's user alone; a job reaches a snapshot only as a read-only mount, because jobs run under that same user id and could otherwise give themselves write permission back.
final readonly class DependencySnapshots
{
    private const string INCOMING = '.incoming';

    private const string LOCK = 'intakt-dependency-snapshots';

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/dependency-snapshots')]
        private string $root,
        #[Autowire(param: HandBackServices::DEPENDENCY_SNAPSHOTS)]
        private int $count,
        private ClockInterface $clock,
        private LockFactory $locks,
    ) {
        if ($count < 1) {
            throw DependencySnapshotCountRefused::below1($count);
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
            $this->evictPastCount();
        } finally {
            $lock->release();
        }
    }

    #[NoDiscard]
    public function mountOf(DependencySnapshotKey $key, string $containerPath): ?JobInput
    {
        $lock = $this->locks->createLock(self::LOCK);
        $lock->acquire(true);

        try {
            if (!is_dir($this->pathOf($key))) {
                return null;
            }

            $this->markUsed($key);

            return new JobInput($this->pathOf($key), $containerPath);
        } finally {
            $lock->release();
        }
    }

    private function pathOf(DependencySnapshotKey $key): string
    {
        return $this->root.'/'.$key->value;
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
        touch($this->pathOf($key), $this->clock->now()->getTimestamp());
    }

    private function evictPastCount(): void
    {
        $snapshots = $this->leastRecentlyUsedFirst();

        foreach (array_slice($snapshots, 0, max(0, count($snapshots) - $this->count)) as $snapshot) {
            $this->remove($snapshot);
        }
    }

    /**
     * @return list<string>
     */
    private function leastRecentlyUsedFirst(): array
    {
        clearstatcache();
        $names = array_values(array_filter(scandir($this->root) ?: [], DependencySnapshotKey::isValue(...)));
        $lastUse = array_map(fn (string $name): int => (int) filemtime($this->root.'/'.$name), $names);
        array_multisort($lastUse, SORT_ASC, $names, SORT_ASC, SORT_STRING);

        return array_map(fn (string $name): string => $this->root.'/'.$name, $names);
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
        /* @var iterable<SplFileInfo> */
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
    }
}
