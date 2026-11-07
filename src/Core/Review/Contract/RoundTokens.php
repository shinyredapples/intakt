<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;

// Tokens, not money, because no price table exists yet; flat, because docs/user/review-output.schema.json documents these fields side by side.
final readonly class RoundTokens
{
    public function __construct(
        public int $calls,
        public int $prompt,
        public int $cacheRead,
        public int $cacheWrite,
        public int $cached,
        public int $completion,
        public int $total,
    ) {
    }

    public static function of(int $calls, TokenFigures $figures): self
    {
        return new self($calls, $figures->prompt, $figures->cacheRead, $figures->cacheWrite, $figures->cached, $figures->completion, $figures->total());
    }
}
