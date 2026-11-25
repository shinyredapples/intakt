<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class RequestedRound
{
    public function __construct(
        // Named by the caller, for example by the branch under review, because rounds of the same change share one finding ledger.
        public string $change,
        // The number the caller expects the round to get, checked once the number is taken under the change's lock, because a round another caller opened first would otherwise take it unnoticed.
        public ?int $number = null,
        // Null for a round whose change was not read from a repository, because the ledger then has no source to start the next round from.
        public ?ChangeSource $source = null,
        // Recorded with the start, because the labels steer the panel, and a round Intakt starts again on the same head must not get a weaker one.
        public Labels $labels = new Labels(),
    ) {
    }
}
