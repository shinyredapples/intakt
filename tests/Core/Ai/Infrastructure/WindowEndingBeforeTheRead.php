<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\Provider;
use Override;

// Forces the order a clock cannot: the earlier window still runs when it is asked to cool down again, so it is kept as the store keeps a running one, and it has ended by the next read.
final class WindowEndingBeforeTheRead implements AccountCooldowns
{
    private bool $earlierWindowRuns = true;

    private int $newWindowSeconds = 0;

    #[Override]
    public function coolDown(Account $account, ?int $resetInSeconds): void
    {
        if ($this->earlierWindowRuns) {
            $this->earlierWindowRuns = false;

            return;
        }

        $this->newWindowSeconds = $resetInSeconds ?? 0;
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
        return $this->earlierWindowRuns || 0 < $this->newWindowSeconds;
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
        return $this->earlierWindowRuns ? 1 : $this->newWindowSeconds;
    }
}
