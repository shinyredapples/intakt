<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\AccountRanking;
use Intakt\Core\Budget\Contract\RankedAccounts;
use Override;

// Stands in because a test about something else than measured room needs every account ranked in the owner's order, and no pick recorded.
final readonly class OwnersOrderRanking implements AccountRanking
{
    #[Override]
    public function rank(array $accounts): RankedAccounts
    {
        return new RankedAccounts($accounts, []);
    }

    #[Override]
    public function record(Provider $provider, RankedAccounts $pick): void
    {
    }
}
