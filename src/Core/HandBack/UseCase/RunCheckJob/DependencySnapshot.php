<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

use Symfony\Component\Lock\SharedLockInterface;

// Holds its entry's shared lock until released, so no eviction removes the folder while a check job binds or copies it.
final readonly class DependencySnapshot
{
    public function __construct(
        public string $path,
        public string $directory,
        private SharedLockInterface $inUse,
    ) {
    }

    public function release(): void
    {
        $this->inUse->release();
    }
}
