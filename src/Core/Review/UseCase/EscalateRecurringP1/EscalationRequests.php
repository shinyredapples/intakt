<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\EscalationRequest;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Infrastructure\EscalationPrompt;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;

// Intakt's own words for a request it cannot make, never the model's, so the failure recorded in the ledger is trusted text.
final readonly class EscalationRequests
{
    public const string FINDING_GONE = 'the finding is not among the findings the round reported';
    public const string FINDING_CUT = 'the diff holds no file the finding names, or their part of it is longer than the escalation prompt holds, so the review could not see the finding\'s code whole';

    public function __construct(
        private StoredFindings $findings,
        private EscalationPrompt $prompt,
    ) {
    }

    public function of(ReviewRound $round, string $id): EscalationRequest|string
    {
        $finding = array_find($this->findings->reportedIn($round), static fn (Finding $reported): bool => $reported->id === $id);

        if (!$finding instanceof Finding) {
            return self::FINDING_GONE;
        }

        $request = new EscalationRequest(new ChangeUnderReview($round->project(), $round->task(), $round->diff()), $finding, $round->id());

        return $this->prompt->showsTheFindingWhole($request) ? $request : self::FINDING_CUT;
    }
}
