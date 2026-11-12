<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;

// A task that is eligible but not ready: the reason names what it waits on.
final readonly class HeldTask
{
    public function __construct(
        public Task $task,
        public string $reason,
    ) {
    }
}
