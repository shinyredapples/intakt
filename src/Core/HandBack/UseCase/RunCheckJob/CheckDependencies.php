<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLease;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshots;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Plugin\Gate\Contract\DependencyStep;
use Intakt\Plugin\Gate\Contract\Gate;
use NoDiscard;

// Null whenever the check installs its dependencies itself, as every check did before snapshots, so a reuse that cannot be proven safe only costs time.
final readonly class CheckDependencies
{
    public function __construct(
        private Gate $gate,
        private CheckedLockFiles $lockFiles,
        private DependencyJobs $jobs,
        private DependencySnapshots $snapshots,
    ) {
    }

    /**
     * @throws HandBackNotChecked
     * @throws DockerEngineFailed
     * @throws JobRefused
     * @throws JobEventNotRecorded
     */
    #[NoDiscard('the lease pins the snapshot and must be released')]
    public function leaseFor(CheckJob $job, string $repository, string $head): ?CheckSnapshot
    {
        $step = $this->gate->dependencyStep($job->configuredCommand);

        if (!$step instanceof DependencyStep) {
            return null;
        }

        $files = $this->lockFiles->of($repository, $head, $step->lockFiles);

        if (null === $files) {
            return null;
        }

        $platform = $this->jobs->platformIn($job->image, $step, $head);

        if (null === $platform) {
            return null;
        }

        $inputs = new LockInputs(array_map(static fn (CheckedLockFile $file): string => $file->bytes, $files), $platform);
        $lease = $this->snapshots->lease($inputs->key()) ?? $this->leaseOfANewInstall($inputs, $step, $job->image);

        return $lease instanceof DependencySnapshotLease ? new CheckSnapshot($lease, $step, $files, $platform) : null;
    }

    private function leaseOfANewInstall(LockInputs $inputs, DependencyStep $step, string $image): ?DependencySnapshotLease
    {
        if (!$this->jobs->installed($inputs, $step, $image)) {
            return null;
        }

        return $this->snapshots->lease($inputs->key());
    }
}
