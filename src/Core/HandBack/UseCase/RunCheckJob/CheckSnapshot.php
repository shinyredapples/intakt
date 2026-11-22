<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLease;
use Intakt\Plugin\Gate\Contract\DependencyStep;

// A leased snapshot with what the check job needs to use it: the lock files it was made for, checked again in the merged tree, and the step that replaces the install.
final readonly class CheckSnapshot
{
    /**
     * @param non-empty-array<string, CheckedLockFile> $lockFiles
     */
    public function __construct(
        public DependencySnapshotLease $lease,
        public DependencyStep $step,
        public array $lockFiles,
    ) {
    }

    // In pairs, path then blob, because docker/job/check.sh reads them so up to its --.
    /**
     * @return list<string>
     */
    public function lockFileArguments(): array
    {
        $arguments = [];

        foreach ($this->lockFiles as $path => $file) {
            $arguments[] = $path;
            $arguments[] = $file->blob;
        }

        return $arguments;
    }

    public function release(): void
    {
        $this->lease->release();
    }
}
