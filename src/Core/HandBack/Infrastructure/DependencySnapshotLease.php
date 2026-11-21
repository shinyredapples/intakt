<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Infrastructure;

use Symfony\Component\Lock\SharedLockInterface;

// Holds the snapshot's shared pin, so no eviction removes the folder while a check mounts it.
final readonly class DependencySnapshotLease
{
    public function __construct(
        public string $folder,
        private SharedLockInterface $pin,
    ) {
    }

    public function release(): void
    {
        $this->pin->release();
    }
}
