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
            $this->resolvedAmong($checking, $this->findings->resolvedIdsOf($round)),
        );
    }

    // Shown as the round checked them, because a round of the change that completed meanwhile may have repeated a finding with new text.
    /**
     * @param list<Finding> $checking
     * @param list<string>  $ids
     *
     * @return list<Finding>
     */
    private function resolvedAmong(array $checking, array $ids): array
    {
        return array_values(array_filter($checking, static fn (Finding $open): bool => in_array($open->id, $ids, true)));
    }
}
