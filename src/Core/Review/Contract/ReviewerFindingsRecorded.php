<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Written when the round completes, not with the verdict, because only then does the change's finding ledger give the reported findings the ids the act list and the judge's merges use.
final readonly class ReviewerFindingsRecorded
{
    /**
     * @param array<string, Severity>|null $severities
     */
    public function __construct(
        public string $round,
        public string $reviewer,
        public FindingIds $findings,
        // The severity this reviewer gave each of its ids, because two reviewers who repeat one tracked finding may rate it apart; null on a row written before it was recorded, so a count leaves that round out instead of guessing.
        public ?array $severities = null,
    ) {
    }
}
