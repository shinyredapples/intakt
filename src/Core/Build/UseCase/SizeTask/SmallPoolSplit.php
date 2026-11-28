<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\SizeTask;

use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Build\Contract\SizingVerdict;

// Plain code and not the model decides this split, because the sizing prompt asks only for the size a task earns by itself (docs/design/05-architecture/03-choosing-work.md, step 3).
final readonly class SmallPoolSplit
{
    // The tier that forces a split is lower when the pool is small, because rework on a large change costs the most (docs/design/05-architecture/03-choosing-work.md, step 3); the top of size M in the plan's size scale (docs/plan.md), so an L task is split on a small pool.
    public const int LOWER_SPLIT_LINES = 400;

    // A one-account install is the small pool, because the planner runs one Developer and one Code reviewer on it (docs/design/05-architecture/06-budget-and-model-planner.md, build slots and panel size).
    private const int SMALL_POOL_ACCOUNTS = 1;

    public function __construct(private EnabledAccounts $accounts)
    {
    }

    public function forces(SizingVerdict $verdict): bool
    {
        return $verdict->estimated_lines >= self::LOWER_SPLIT_LINES && $this->poolIsSmall();
    }

    private function poolIsSmall(): bool
    {
        return array_sum(array_map(fn (Provider $provider): int => count($this->accounts->of($provider)), Provider::cases())) <= self::SMALL_POOL_ACCOUNTS;
    }
}
