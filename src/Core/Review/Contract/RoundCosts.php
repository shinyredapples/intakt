<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Budget\Contract\Cost;

// One computation of a round's cost, so the console and the dashboard show the same figure.
interface RoundCosts
{
    public function costOf(string $roundId): Cost;

    // Several rounds at once, because a review page with several rounds would read the ledger once more for each.
    public function costsOf(string ...$roundIds): RoundCostList;
}
