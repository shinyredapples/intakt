<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobId;

// Tried without waiting, because a busy lock means a living run owns the job and the sweep must leave it alone.
final readonly class OrphanedJobs
{
    public function __construct(
        private DockerEngine $engine,
        private JobOutputDirectories $outputs,
        private JobLocks $locks,
        private JobLedger $ledger,
    ) {
    }

    /**
     * @return list<JobContainer> the containers removed
     */
    public function removeAll(): array
    {
        $removed = array_values(array_filter($this->engine->jobContainers(), $this->removeContainer(...)));
        array_map($this->removeDirectory(...), $this->outputs->leftOver());

        return $removed;
    }

    private function removeContainer(JobContainer $container): bool
    {
        $lock = $this->locks->of($container->job);

        if (!$lock->acquire()) {
            return false;
        }

        try {
            $this->engine->remove($container->id);
            $this->outputs->remove($container->job);
            $this->ledger->orphanRemoved($container);
        } finally {
            $lock->release();
        }

        return true;
    }

    private function removeDirectory(JobId $job): void
    {
        $lock = $this->locks->of($job);

        if (!$lock->acquire()) {
            return;
        }

        try {
            $this->outputs->remove($job);
        } finally {
            $lock->release();
        }
    }
}
