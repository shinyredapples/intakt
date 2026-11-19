<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\Provider;
use Override;
use Symfony\Component\Clock\MockClock;

// Keeps the account windows as AccountCooldownWindows does (coolDown() keeps a running one, coolDownAtLeast() replaces one that ends first) but on a mocked clock, because the RateLimiter reads the system time and a test must force a window to end at a chosen moment; every read of the wait takes the given seconds, so a window can end between the refresh and the read.
final class AccountWindowsOnAClock implements AccountCooldowns
{
    /**
     * @var array<string, DateTimeImmutable>
     */
    private array $ends = [];

    /**
     * @param non-empty-list<string> $names
     */
    public function __construct(
        private readonly MockClock $clock,
        private readonly array $names,
        private readonly int $secondsEachReadTakes,
    ) {
    }

    #[Override]
    public function coolDown(Account $account, ?int $resetInSeconds): void
    {
        if ($this->isCoolingDown($account)) {
            return;
        }

        $this->ends[$account->key()] = $this->clock->now()->modify(sprintf('+%d seconds', $resetInSeconds ?? 60));
    }

    #[Override]
    public function coolDownAtLeast(Account $account, int $seconds): void
    {
        $until = $this->clock->now()->modify(sprintf('+%d seconds', $seconds));
        if ($this->isCoolingDown($account) && $this->endOf($account) >= $until) {
            return;
        }

        $this->ends[$account->key()] = $until;
    }

    #[Override]
    public function coolDownUntilPlanResets(Account $account): void
    {
    }

    #[Override]
    public function coolDownRejected(Account $account): void
    {
    }

    #[Override]
    public function answered(Account $account, DateTimeImmutable $sentAt): void
    {
    }

    #[Override]
    public function isCoolingDown(Account $account): bool
    {
        $end = $this->ends[$account->key()] ?? null;

        return $end instanceof DateTimeImmutable && $this->clock->now() < $end;
    }

    #[Override]
    public function isRejected(Account $account): bool
    {
        return false;
    }

    #[Override]
    public function isEveryAccountRejected(Provider $provider): bool
    {
        return false;
    }

    #[Override]
    public function secondsUntilAnAccountIsFree(Provider $provider): int
    {
        $this->clock->sleep($this->secondsEachReadTakes);

        return min(array_map(fn (string $name): int => $this->secondsUntilFree(new Account($provider, $name)), $this->names));
    }

    public function endOf(Account $account): ?DateTimeImmutable
    {
        return $this->ends[$account->key()] ?? null;
    }

    private function secondsUntilFree(Account $account): int
    {
        $end = $this->endOf($account);
        if (!$this->isCoolingDown($account) || !$end instanceof DateTimeImmutable) {
            return 0;
        }

        return max(1, $end->getTimestamp() - $this->clock->now()->getTimestamp());
    }
}
