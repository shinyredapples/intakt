<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Computed from what the round recorded, so the summary under the live log never counts what the page happened to see streamed.
interface SessionSummaries
{
    public function ofRound(string $roundId): SessionSummary;
}
