<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\Provider;
use Override;
use Symfony\Component\Clock\ClockInterface;

// Account windows judged against a mocked clock, because the real store's rate limiter reads the system time; like the real store it keeps a running window and refuses a new one beside it.
final class AccountWindowsOnAClock implements AccountCooldowns
{
    /**
     * @var array<string, DateTimeImmutable>
     */
    private array $ends = [];

    /**
     * @param array<string, list<string>> $accounts
     */
    public function __construct(private readonly ClockInterface $clock, private readonly array $accounts)
    {
    }

    #[Override]
    public function coolDown(Account $account, ?int $resetInSeconds): void
    {
        if ($this->isCoolingDown($account)) {
            return;
        }

        $this->ends[$account->key()] = $this->clock->now()->modify(sprintf('+%d seconds', $resetInSeconds ?? self::SECONDS_WITHOUT_RESET));
    }

    #[Override]
    public function coolDownUntilPlanResets(Account $account): void
    {
        $this->coolDown($account, null);
    }

    #[Override]
    public function coolDownRejected(Account $account): void
    {
        $this->coolDown($account, null);
    }

    #[Override]
    public function answered(Account $account, DateTimeImmutable $sentAt): void
    {
    }

    #[Override]
    public function isCoolingDown(Account $account): bool
    {
        return 0 < $this->secondsUntilFree($account);
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
    public function secondsUntilFree(Account $account): int
    {
        $end = $this->ends[$account->key()] ?? null;
        $left = null === $end ? 0 : $end->getTimestamp() - $this->clock->now()->getTimestamp();

        return max(0, $left);
    }

    #[Override]
    public function secondsUntilAnAccountIsFree(Provider $provider): int
    {
        $waits = array_map(fn (string $name): int => $this->secondsUntilFree(new Account($provider, $name)), $this->accounts[$provider->value] ?? []);

        return [] === $waits ? 0 : min($waits);
    }
}
