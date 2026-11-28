<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Review\Contract\RoundCostList;
use Intakt\Core\Review\Contract\RoundCosts;

final readonly class UnpricedRoundCosts implements RoundCosts
{
    public function costOf(string $roundId): Cost
    {
        return Cost::none();
    }

    public function costsOf(string ...$roundIds): RoundCostList
    {
        return new RoundCostList(array_fill_keys($roundIds, Cost::none()));
    }

    public function costsIn(RecordedEvents $usage, string ...$roundIds): RoundCostList
    {
        return $this->costsOf(...$roundIds);
    }
}
