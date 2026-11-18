<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Tier;

// What one reviewer of a round did, read back from the round and the ledger, because a bench scores each reviewer on its own.
final readonly class ReviewerOutcome
{
    public function __construct(
        public string $role,
        public Tier $tier,
        // Null for a reviewer that failed or has not finished, because it gave none.
        public ?Verdict $verdict,
        // In plain words because it reaches a console, and null unless the reviewer failed.
        public ?string $failure,
        public RoundTokens $tokens,
        public ?int $milliseconds,
        // The models the reviewer's calls ran on, from the ledger, because the tier alone does not say which model answered; empty when no call named one.
        public ModelNames $models,
    ) {
    }
}
