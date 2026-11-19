<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Budget\Contract\Cost;

// One computation of a round's cost, so the console and the dashboard show the same figure.
interface RoundCosts
{
    public function costOf(string $roundId): Cost;

    // Keyed by every round asked, in the order asked, read in one pass, because a page that prices every round must not reread the whole usage history once per round.
    /**
     * @param list<string> $roundIds
     *
     * @return array<string, Cost>
     */
    public function costsOf(array $roundIds): array;
}
