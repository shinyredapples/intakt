<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\Domain\RoundReviewer;

// The ids of the change's finding ledger, not the reviewer's own, because those are the ids the act list and the judge's merges name.
final readonly class ReportedFindings
{
    /**
     * @param list<string> $findings
     */
    public function __construct(
        public RoundReviewer $reviewer,
        public array $findings,
    ) {
    }
}
