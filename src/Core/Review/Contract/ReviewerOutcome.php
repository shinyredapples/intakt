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
        // Null when the reviewer gave none, which a failed or unfinished reviewer does not.
        public ?Verdict $verdict,
        // In plain words, null unless the reviewer failed.
        public ?string $failure,
        public RoundTokens $tokens,
        public ?int $milliseconds,
    ) {
    }
}
