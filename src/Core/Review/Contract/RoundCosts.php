<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Ledger\Contract\RecordedEvent;

// One computation of a round's cost, so the console and the dashboard show the same figure.
interface RoundCosts
{
    public function costOf(string $roundId): Cost;

    // Priced from usage rows the caller already read, because a page that reads the ledger once must not read the usage again per round, and its tokens and costs must come from the same rows.
    /**
     * @param list<RecordedEvent> $usageRows
     */
    public function costOfIn(string $roundId, array $usageRows): Cost;
}
