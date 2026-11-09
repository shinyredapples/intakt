<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

// A running job holds its lock, and the operating system frees it when the orchestrator dies, so the sweep can tell a job without an owner.
final readonly class JobLocks
{
    public function __construct(
        private LockFactory $locks,
    ) {
    }

    // Never waits, because a busy lock means a live owner and the caller has no use for it then.
    public function claim(string $jobId): ?LockInterface
    {
        $lock = $this->locks->createLock('job-'.$jobId);

        return $lock->acquire() ? $lock : null;
    }
}
