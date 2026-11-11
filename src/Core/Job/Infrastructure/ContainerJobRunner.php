<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use NoDiscard;

final readonly class ContainerJobRunner implements JobRunner
{
    public function __construct(
        private JobLocks $locks,
        private OrphanedJobs $orphans,
        private JobOutputDirectories $outputs,
        private JobContainers $containers,
        private JobLedger $ledger,
        private StartCheckReport $startCheck,
    ) {
    }

    // The lock is held from before the container exists until it is gone, so a sweep never takes a living job for an orphan.
    #[NoDiscard('the cause and the output are only reported through the result')]
    public function run(JobSpec $spec): JobResult
    {
        $lock = $this->locks->of($spec->id);

        if (!$lock->acquire()) {
            throw JobRefused::alreadyRunning($spec->id);
        }

        try {
            return $this->runAlone($spec);
        } finally {
            $lock->release();
        }
    }

    // Leftovers of a crashed orchestrator are removed before each start, so the next job repairs what the crash left.
    private function runAlone(JobSpec $spec): JobResult
    {
        $this->orphans->removeAll($spec->id);
        $directory = $this->outputs->prepare($spec->id);

        try {
            $end = $this->containers->runToEnd($spec, $directory);
            $this->recordStartCheck($spec, $directory);
            $this->ledger->jobEnded($spec->id, $end);

            return new JobResult($end, $this->outputs->read($directory));
        } finally {
            $this->outputs->remove($spec->id);
        }
    }

    // Recorded with its reason, so a job that ran in its reduced mode is never taken for one that could do everything it was told.
    private function recordStartCheck(JobSpec $spec, string $directory): void
    {
        foreach ($this->startCheck->failuresIn($directory) as $failure) {
            $this->ledger->startCheckFailed($spec->id, $failure);
        }
    }
}
