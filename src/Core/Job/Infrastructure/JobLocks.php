<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

// A running job holds its lock; the operating system frees it when the orchestrator dies, which is how the sweep knows a job has no owner.
final readonly class JobLocks
{
    public function __construct(
        private LockFactory $locks,
    ) {
    }

    // Never waits: a busy lock means a live owner.
    public function claim(string $jobId): ?LockInterface
    {
        $lock = $this->locks->createLock('job-'.$jobId);

        return $lock->acquire() ? $lock : null;
    }
}
