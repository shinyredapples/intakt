<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Core\Review\Contract\SessionSummaries;
use Intakt\Core\Review\Contract\SessionSummary;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Override;

final readonly class StoredSessionSummaries implements SessionSummaries
{
    public function __construct(
        private ReviewRounds $rounds,
        private StoredFindings $findings,
        private RoundCosts $costs,
        private RoundToolCalls $toolCalls,
    ) {
    }

    #[Override]
    public function ofRound(string $roundId): SessionSummary
    {
        $round = $this->rounds->stored($roundId);
        $checking = $this->findings->openFor($round);

        return new SessionSummary(
            $this->toolCalls->countedIn($round),
            $this->costs->costOf($roundId),
            $this->toolCalls->filesReadIn($round),
            $checking,
            $this->findings->reportedIn($round),
            $this->resolvedAmong($checking, $this->findings->resolvedBy($round)),
        );
    }

    // Taken from the open findings the round checked when one equals a kept finding in id and content, because a round completed before resolved findings were kept under ledger ids kept them under its reviewer's ids, and such an id can be another open finding's ledger id.
    /**
     * @param list<Finding> $checking
     * @param list<Finding> $resolved
     *
     * @return list<Finding>
     */
    private function resolvedAmong(array $checking, array $resolved): array
    {
        $kept = array_map(static fn (Finding $finding): string => json_encode($finding, JSON_THROW_ON_ERROR), $resolved);

        return array_values(array_filter($checking, static fn (Finding $open): bool => in_array(json_encode($open, JSON_THROW_ON_ERROR), $kept, true)));
    }
}
