<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Infrastructure;

use Intakt\Core\Job\Contract\JobInput;
use Symfony\Component\Lock\SharedLockInterface;

// Holds a shared pin on the snapshot, so no save evicts it while the job that mounts it still runs; the pin also ends when the lease is dropped.
final readonly class DependencySnapshotLease
{
    public function __construct(
        public JobInput $input,
        private SharedLockInterface $pin,
    ) {
    }

    public function release(): void
    {
        $this->pin->release();
    }
}
