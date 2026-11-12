<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;

enum WorkerState: string
{
    case Free = 'free';
    case NoFreeSlot = 'no-free-slot';
    case Paused = 'paused';
    case Draining = 'draining';
    case HeldByBudget = 'held-by-budget';

    public function takesWork(): bool
    {
        return self::Free === $this;
    }
}
