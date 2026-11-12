<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;

// Because a held task is eligible but not ready, its reason says what it waits on.
final readonly class HeldTask
{
    public function __construct(
        public Task $task,
        public string $reason,
    ) {
    }
}
