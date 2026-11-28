<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Ledger\Contract\RecordedEvents;

// One computation of a round's cost, so the console and the dashboard show the same figure.
interface RoundCosts
{
    public function costOf(string $roundId): Cost;

    // Several rounds at once, because a review page with several rounds would read the ledger once more for each.
    public function costsOf(string ...$roundIds): RoundCostList;

    // For a caller that already read the usage rows, because a second read could hold a call the caller's token totals lack.
    public function costsIn(RecordedEvents $usage, string ...$roundIds): RoundCostList;
}
