<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Closure;
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

    // Networks after containers, because the engine removes no network a container still uses.
    /**
     * @return list<JobContainer>
     */
    public function removeAll(?JobId $held = null): array
    {
        $removed = array_values(array_filter($this->engine->jobContainers(), fn (JobContainer $container): bool => $this->removeContainer($container, $held)));
        array_map(fn (JobNetwork $network): bool => $this->removeNetwork($network, $held), $this->engine->jobNetworks());
        array_map(fn (JobId $job): bool => $this->whileFree($job, null, fn () => $this->outputs->remove($job)), $this->outputs->leftOver());

        return $removed;
    }

    private function removeContainer(JobContainer $container, ?JobId $held): bool
    {
        return $this->whileFree($container->job, $held, function () use ($container): void {
            $this->engine->remove($container->id);
            $this->outputs->remove($container->job);
            $this->ledger->orphanRemoved($container);
        });
    }

    private function removeNetwork(JobNetwork $network, ?JobId $held): bool
    {
        return $this->whileFree($network->job, $held, function () use ($network): void {
            $this->engine->removeNetwork($network->name);
            $this->ledger->networkRemoved($network);
        });
    }

    // The job whose lock the caller holds counts as free, since nothing of its own run exists yet, and what is left of it would block its names.
    private function whileFree(JobId $job, ?JobId $held, Closure $removal): bool
    {
        if ($held?->value === $job->value) {
            $removal();

            return true;
        }

        $lock = $this->locks->of($job);

        if (!$lock->acquire()) {
            return false;
        }

        try {
            $removal();
        } finally {
            $lock->release();
        }

        return true;
    }
}
