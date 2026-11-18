<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

use FilesystemIterator;
use Intakt\Core\HandBack\Contract\HandBackServices;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Plugin\Gate\Contract\DependencyInstall;
use Intakt\Plugin\Gate\Contract\Gate;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

// Written by the runner alone and mounted read-only into checks, so a builder can never plant a package that a later check of another head would run.
// Each key has one lock: shared while checks use its entry, exclusive while one check saves it or another evicts it, so no check meets a half-saved or removed folder.
final readonly class DependencySnapshots
{
    public const string LOCK_PREFIX = 'dependency-snapshot-';

    private const string STAGING = '.staging';

    private const string NAME = '/\A(?<key>[0-9a-f]{64})(?<staging>\.staging)?\z/D';

    public function __construct(
        private Gate $gate,
        private RepositoryGit $git,
        private DependencyInstallJob $install,
        private LockFactory $locks,
        private ClockInterface $clock,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/var/dependencies')]
        private string $root,
        #[Autowire(param: HandBackServices::KEPT_DEPENDENCY_SNAPSHOTS)]
        private int $kept,
    ) {
        if ($kept < 1) {
            throw new InvalidArgumentException(sprintf('The dependency snapshot store keeps at least one snapshot; %d was configured.', $kept));
        }
    }

    /**
     * The caller releases the snapshot once its job ended, because the entry is safe from eviction only until then.
     *
     * @throws DockerEngineFailed
     * @throws JobEventNotRecorded
     */
    public function of(string $repository, string $head, string $image): ?DependencySnapshot
    {
        $install = $this->gate->dependencyInstall();

        if (!$install instanceof DependencyInstall) {
            return null;
        }

        $lockFiles = $this->lockFilesAt($repository, $head, $install);

        if (null === $lockFiles) {
            return null;
        }

        $source = new DependencySource($install, $image, $lockFiles);
        $lock = $this->lockOf($source->key());

        // Without waiting, so a check whose snapshot another check is saving or evicting installs itself as before.
        if (!$lock->acquireRead()) {
            return null;
        }

        $entry = $this->root.'/'.$source->key();

        if ($this->isEntry($entry)) {
            return $this->used($entry, $install, $lock);
        }

        $lock->release();

        return $this->saved($source, $entry, $lock);
    }

    /**
     * @return array<string, string>|null
     */
    private function lockFilesAt(string $repository, string $head, DependencyInstall $install): ?array
    {
        $lockFiles = [];

        foreach ($install->lockFiles as $path) {
            if ('blob' !== $this->git->answer($repository, ['cat-file', '-t', $head.':'.$path])) {
                return null;
            }

            $lockFiles[$path] = $this->git->exactOutput($repository, ['cat-file', 'blob', $head.':'.$path]);
        }

        return $lockFiles;
    }

    // No expiry, because an install or a check may run longer than a default lock lives.
    private function lockOf(string $key): SharedLockInterface
    {
        return $this->locks->createLock(self::LOCK_PREFIX.$key, ttl: null);
    }

    private function isEntry(string $path): bool
    {
        return !is_link($path) && is_dir($path);
    }

    // Touched with the clock's time while the shared lock is held, so the entry exists and the least recently used one is evicted first.
    private function used(string $entry, DependencyInstall $install, SharedLockInterface $lock): DependencySnapshot
    {
        touch($entry, $this->clock->now()->getTimestamp());

        return new DependencySnapshot($entry, $install->directory, $lock);
    }

    // Looked at again under the exclusive lock, because another check may have saved the entry since the shared lock was let go.
    private function saved(DependencySource $source, string $entry, SharedLockInterface $lock): ?DependencySnapshot
    {
        if (!$lock->acquire()) {
            return null;
        }

        $saved = false;

        try {
            $saved = $this->isEntry($entry) || $this->installedAs($source, $entry);
        } finally {
            if (!$saved) {
                $lock->release();
            }
        }

        // Shared again before the eviction, so other checks may use the entry while this one runs.
        if (!$saved || !$lock->acquireRead()) {
            return null;
        }

        $this->evictBeyondTheCount(basename($entry));

        return $this->used($entry, $source->install, $lock);
    }

    /**
     * @throws DockerEngineFailed
     * @throws JobEventNotRecorded
     */
    private function installedAs(DependencySource $source, string $entry): bool
    {
        $this->filesystem->mkdir($this->root, 0o755);
        $this->filesystem->chmod($this->root, 0o755);
        $staging = $entry.self::STAGING;
        // Whatever stands at either path is a leftover, because only the holder of the key's exclusive lock writes them.
        $this->filesystem->remove([$staging, $entry]);

        try {
            if (!$this->install->installed($source, $staging)) {
                return false;
            }

            $this->sealed($staging.'/kept');
            $this->filesystem->rename($staging.'/kept', $entry);
        } finally {
            $this->filesystem->remove($staging);
        }

        return true;
    }

    // Links are skipped, because chmod follows them and the job chose where they point.
    private function sealed(string $directory): void
    {
        $this->filesystem->chmod($directory, 0o755);
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($entries as $file) {
            if ($file instanceof SplFileInfo && !$file->isLink()) {
                $this->filesystem->chmod($file->getPathname(), $this->modeOf($file));
            }
        }
    }

    // Writable by the runner alone; an executable keeps its execute bits, because the check runs package binaries from it.
    private function modeOf(SplFileInfo $file): int
    {
        return $file->isDir() || 0 !== ($file->getPerms() & 0o100) ? 0o755 : 0o644;
    }

    // The entry just saved is left out, so a save never evicts its own snapshot; a staging folder whose key nobody holds is a crash's leftover.
    private function evictBeyondTheCount(string $saved): void
    {
        $used = [];
        // Cleared first, because another check touched its snapshot since this process last read the times.
        clearstatcache();

        $names = scandir($this->root);

        foreach (false === $names ? [] : $names as $name) {
            if (1 !== preg_match(self::NAME, $name, $match) || $saved === $name) {
                continue;
            }

            if ('' !== ($match['staging'] ?? '')) {
                $this->removedUnlessHeld($match['key'], $name);
            } else {
                $used[$name] = (int) filemtime($this->root.'/'.$name);
            }
        }

        arsort($used, SORT_NUMERIC);

        foreach (array_slice(array_keys($used), $this->kept - 1) as $name) {
            $this->removedUnlessHeld($name, $name);
        }
    }

    // Skipped while a check holds the key, so a folder a job is binding, copying or saving stays; a later save evicts it.
    private function removedUnlessHeld(string $key, string $name): void
    {
        $lock = $this->lockOf($key);

        if (!$lock->acquire()) {
            return;
        }

        try {
            $this->filesystem->remove($this->root.'/'.$name);
        } finally {
            $lock->release();
        }
    }
}
