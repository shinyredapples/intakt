<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Review\Contract\RoundCosts;

final readonly class UnpricedRoundCosts implements RoundCosts
{
    public function costOf(string $roundId): Cost
    {
        return Cost::none();
    }

    public function costsOf(array $roundIds): array
    {
        return array_fill_keys($roundIds, Cost::none());
    }
}
