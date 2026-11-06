<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Ledger\Contract\Ledger;

final readonly class LedgerReviewPages
{
    public function __construct(
        private Ledger $ledger,
        private RoundPayloads $payloads,
    ) {
    }

    // Null for a round the ledger never started readably, so the page answers 404 instead of an empty review.
    public function reviewOf(string $round): ?Review
    {
        $rounds = new LedgerRounds($this->payloads);

        foreach ($this->ledger->readInOrder() as $recorded) {
            $rounds->add($recorded);
        }

        return $rounds->reviewOf($round);
    }
}
