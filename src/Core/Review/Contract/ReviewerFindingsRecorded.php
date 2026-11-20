<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Written when the round completes, not with the verdict, because only then does the change's finding ledger give the reported findings the ids the act list and the judge's merges use.
final readonly class ReviewerFindingsRecorded
{
    /**
     * @param list<string> $findings
     */
    public function __construct(
        public string $round,
        public string $reviewer,
        public array $findings,
    ) {
    }
}
