<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Review\Contract\EscalationRequest;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;

// Intakt's own words for a request it cannot make, never the model's, so the failure recorded in the ledger is trusted text.
final readonly class EscalationRequests
{
    public const string FINDING_GONE = 'the finding is not among the findings the round reported';

    public function __construct(
        private StoredFindings $findings,
    ) {
    }

    // The finding alone with the round's diff, never the other findings, so the escalation judges this finding on its own.
    public function of(ReviewRound $round, string $id): EscalationRequest|string
    {
        $finding = array_find($this->findings->reportedIn($round), static fn (Finding $reported): bool => $reported->id === $id);

        return $finding instanceof Finding ? new EscalationRequest($finding, $round->diff()) : self::FINDING_GONE;
    }
}
