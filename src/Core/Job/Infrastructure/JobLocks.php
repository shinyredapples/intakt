<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobId;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

// A file lock dies with its process, so a job whose lock is free has no living orchestrator and its container is an orphan.
final readonly class JobLocks
{
    public function __construct(private LockFactory $locks)
    {
    }

    public function of(JobId $job): SharedLockInterface
    {
        return $this->locks->createLock('intakt-job-'.$job->value, ttl: null);
    }
}
