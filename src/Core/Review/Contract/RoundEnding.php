<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Carries the reason with the end, because every door must print the same words for it and none may spell them again.
final readonly class RoundEnding
{
    public function __construct(
        public RoundEnds $end,
        public string $reason,
    ) {
    }
}
