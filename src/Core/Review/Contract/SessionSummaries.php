<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Computed from what the round recorded, so the summary under the live log never counts what the page happened to see streamed.
interface SessionSummaries
{
    // Null when no round is stored under the id, because the ledger also names rounds started before Intakt stored them.
    public function ofRound(string $roundId): ?SessionSummary;
}
