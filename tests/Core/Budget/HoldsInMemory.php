<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\Holds;
use Intakt\Core\Budget\Contract\ReadingOutcome;
use Intakt\Core\Budget\Contract\SharedHold;
use Override;
use Symfony\Component\Clock\ClockInterface;

// Keeps every hold as given and checks no reset, so a guard test decides on the holds alone.
final class HoldsInMemory implements Holds
{
    /** @var array<string, DateTimeImmutable> */
    private array $cooldowns = [];

    /** @var list<array{SharedHold, DateTimeImmutable}> */
    private array $shared = [];

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    #[Override]
    public function coolDown(Account $account, DateTimeImmutable $resetsAt): ReadingOutcome
    {
        $this->cooldowns[$account->key()] = $resetsAt;

        return ReadingOutcome::Recorded;
    }

    #[Override]
    public function hold(SharedHold $hold, DateTimeImmutable $until): void
    {
        $this->shared[] = [$hold, $until];
    }

    #[Override]
    public function isCoolingDown(Account $account): bool
    {
        return $this->coolingDownUntil($account) instanceof DateTimeImmutable;
    }

    #[Override]
    public function coolingDownUntil(Account $account): ?DateTimeImmutable
    {
        $until = $this->cooldowns[$account->key()] ?? null;

        return null !== $until && $until > $this->clock->now() ? $until : null;
    }

    #[Override]
    public function sharedInEffect(): array
    {
        $inEffect = array_map(static fn (array $hold): SharedHold => $hold[0], array_filter($this->shared, fn (array $hold): bool => $hold[1] > $this->clock->now()));

        return array_values(array_filter(SharedHold::cases(), static fn (SharedHold $hold): bool => in_array($hold, $inEffect, true)));
    }
}
