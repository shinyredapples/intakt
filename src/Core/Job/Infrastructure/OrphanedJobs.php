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
     * @return list<JobContainer>
     */
    public function removeAll(?JobId $held = null): array
    {
        $removed = array_values(array_filter($this->engine->jobContainers(), fn (JobContainer $container): bool => $this->removeContainer($container, $held)));
        array_map($this->removeDirectory(...), $this->outputs->leftOver());

        return $removed;
    }

    // A container of the job whose lock the caller holds is a crashed run's, since its own is not created yet, and it would block the name.
    private function removeContainer(JobContainer $container, ?JobId $held): bool
    {
        if ($held?->value === $container->job->value) {
            $this->engine->remove($container->id);
            $this->ledger->orphanRemoved($container);

            return true;
        }

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
