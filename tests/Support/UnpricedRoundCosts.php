<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Review\Contract\RoundCosts;
use Override;

final readonly class UnpricedRoundCosts implements RoundCosts
{
    public function costOf(string $roundId): Cost
    {
        return Cost::none();
    }

    #[Override]
    public function costOfIn(string $roundId, array $usageRows): Cost
    {
        return Cost::none();
    }
}
