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

// Written by the runner alone and mounted read-only into checks, so a builder can never plant a package that a later check of another head would run.
final readonly class DependencySnapshots
{
    public const string LOCK_PREFIX = 'dependency-snapshot-';

    private const string ENTRY = '/\A[0-9a-f]{64}\z/D';

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
     * @throws DockerEngineFailed
     * @throws JobEventNotRecorded
     */
    public function of(string $repository, string $head, string $image): ?string
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
        $entry = $this->root.'/'.$source->key();

        return is_dir($entry) ? $this->used($entry) : $this->saved($source, $entry);
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

    // Touched with the clock's time, because the least recently used snapshot is the one evicted.
    private function used(string $entry): string
    {
        touch($entry, $this->clock->now()->getTimestamp());

        return $entry;
    }

    // Without waiting, so a check whose lock files another check is installing installs them itself as before.
    private function saved(DependencySource $source, string $entry): ?string
    {
        // No expiry, because an install may run longer than a default lock lives.
        $lock = $this->locks->createLock(self::LOCK_PREFIX.basename($entry), ttl: null);

        if (!$lock->acquire()) {
            return null;
        }

        try {
            return is_dir($entry) ? $this->used($entry) : $this->installedAs($source, $entry);
        } finally {
            $lock->release();
        }
    }

    private function installedAs(DependencySource $source, string $entry): ?string
    {
        $this->filesystem->mkdir($this->root, 0o755);
        $this->filesystem->chmod($this->root, 0o755);
        $staging = $entry.'.staging';
        $this->filesystem->remove($staging);

        if (!$this->install->installed($source, $staging)) {
            $this->filesystem->remove($staging);

            return null;
        }

        $this->sealed($staging.'/kept');
        $this->filesystem->rename($staging.'/kept', $entry);
        $this->filesystem->remove($staging);
        $this->used($entry);
        $this->evictBeyondTheCount();

        return $entry;
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

    private function evictBeyondTheCount(): void
    {
        $used = [];
        // Cleared first, because another check touched its snapshot since this process last read the times.
        clearstatcache();

        foreach (scandir($this->root) ?: [] as $name) {
            if (1 === preg_match(self::ENTRY, $name)) {
                $used[$name] = (int) filemtime($this->root.'/'.$name);
            }
        }

        arsort($used, SORT_NUMERIC);

        foreach (array_slice(array_keys($used), $this->kept) as $name) {
            $this->filesystem->remove($this->root.'/'.$name);
        }
    }
}
