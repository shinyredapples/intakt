<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\Provider;
use Override;

// Stands in where a test is about something else than cooldowns: every account is free.
final readonly class NoCooldowns implements AccountCooldowns
{
    #[Override]
    public function coolDown(Account $account, ?int $resetInSeconds): void
    {
    }

    #[Override]
    public function isCoolingDown(Account $account): bool
    {
        return false;
    }

    #[Override]
    public function secondsUntilAnAccountIsFree(Provider $provider): int
    {
        return 0;
    }
}
