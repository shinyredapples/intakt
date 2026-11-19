<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\Domain\RoundReviewer;

final readonly class ReportedFindings
{
    /**
     * @param list<string> $findings the ledger ids of the change's findings, as the act list and the judge's merges name them
     */
    public function __construct(
        public RoundReviewer $reviewer,
        public array $findings,
    ) {
    }
}
