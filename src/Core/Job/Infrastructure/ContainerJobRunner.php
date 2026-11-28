<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Job\Contract\JobWatch;
use NoDiscard;

final readonly class ContainerJobRunner implements JobRunner
{
    public function __construct(
        private JobLocks $locks,
        private OrphanedJobs $orphans,
        private JobOutputDirectories $outputs,
        private JobContainers $containers,
        private JobLedger $ledger,
        private StartCheckLog $startCheck,
    ) {
    }

    // The lock is held from before the container exists until it is gone, so a sweep never takes a living job for an orphan.
    #[NoDiscard('the cause and the output are only reported through the result')]
    public function run(JobSpec $spec, ?JobWatch $watch = null): JobResult
    {
        $lock = $this->locks->of($spec->id);

        if (!$lock->acquire()) {
            throw JobRefused::alreadyRunning($spec->id);
        }

        try {
            return $this->runAlone($spec, $watch);
        } finally {
            $lock->release();
        }
    }

    // Leftovers of a crashed orchestrator are removed before each start, so the next job repairs what the crash left.
    private function runAlone(JobSpec $spec, ?JobWatch $watch): JobResult
    {
        $this->orphans->removeAll($spec->id);
        $directory = $this->outputs->prepare($spec->id);

        try {
            $live = $watch instanceof JobWatch ? new LiveOutputWatch($watch, $this->outputs, $directory) : null;
            $ended = $this->containers->runToEnd($spec, $directory, $live);
            $this->recordStartCheck($spec, $ended);
            $this->ledger->jobEnded($spec->id, $ended->end);

            return new JobResult($ended->end, $this->outputs->read($directory));
        } finally {
            $this->outputs->remove($spec->id);
        }
    }

    // Recorded with its reason, so a job that ran in its reduced mode is never taken for one that could do everything it was told.
    private function recordStartCheck(JobSpec $spec, ContainerEnd $ended): void
    {
        foreach ($this->startCheck->failuresIn($ended->standardError, $ended->mark) as $failure) {
            $this->ledger->startCheckFailed($spec->id, $failure);
        }
    }
}
