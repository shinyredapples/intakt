<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\Provider;
use Override;

// Stands in because a test about something else than cooldowns needs every account free.
final readonly class NoCooldowns implements AccountCooldowns
{
    #[Override]
    public function coolDown(Account $account, ?int $resetInSeconds): void
    {
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
        return false;
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
        return 0;
    }

    #[Override]
    public function secondsUntilAnAccountIsFree(Provider $provider): int
    {
        return 0;
    }
}
