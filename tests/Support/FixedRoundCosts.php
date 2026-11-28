<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Review\Contract\RoundCostList;
use Intakt\Core\Review\Contract\RoundCosts;

// Every round at one figure, so a dashboard test can tell a round's cost reached the page without pricing any call.
final readonly class FixedRoundCosts implements RoundCosts
{
    public function __construct(private Cost $cost)
    {
    }

    public function costOf(string $roundId): Cost
    {
        return $this->cost;
    }

    public function costsOf(string ...$roundIds): RoundCostList
    {
        return new RoundCostList(array_fill_keys($roundIds, $this->cost));
    }

    public function costsIn(RecordedEvents $usage, string ...$roundIds): RoundCostList
    {
        return $this->costsOf(...$roundIds);
    }
}
