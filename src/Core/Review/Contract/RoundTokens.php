<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Tokens, not money, because no price table exists yet.
final readonly class RoundTokens
{
    public function __construct(
        public int $calls,
        public int $prompt,
        public int $completion,
        public int $total,
    ) {
    }
}
