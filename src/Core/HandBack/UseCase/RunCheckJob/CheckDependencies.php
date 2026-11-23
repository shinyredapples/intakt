<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLease;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshots;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Plugin\Gate\Contract\DependencyStep;
use Intakt\Plugin\Gate\Contract\Gate;
use NoDiscard;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Lock\Exception\ExceptionInterface;

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
        try {
            $saved = $this->snapshots->lease($inputs->key());
        } catch (IOException|ExceptionInterface) {
            // No install, because a snapshot that may exist but cannot be leased would be installed again on every lookup and still not be reused.
            return null;
        }

        $lease = $saved ?? $this->leaseOfANewInstall($inputs, $step, $job->image);

        return $lease instanceof DependencySnapshotLease ? new CheckSnapshot($lease, $step, $files, $platform) : null;
    }

    /**
     * @throws DockerEngineFailed
     * @throws JobEventNotRecorded
     */
    private function leaseOfANewInstall(LockInputs $inputs, DependencyStep $step, string $image): ?DependencySnapshotLease
    {
        if (!$this->jobs->installed($inputs, $step, $image)) {
            return null;
        }

        return $this->leaseOf($inputs);
    }

    private function leaseOf(LockInputs $inputs): ?DependencySnapshotLease
    {
        try {
            return $this->snapshots->lease($inputs->key());
        } catch (IOException|ExceptionInterface) {
            // A store that cannot be opened or a snapshot that cannot be pinned only costs the reuse, because the check then installs its dependencies itself.
            return null;
        }
    }
}
