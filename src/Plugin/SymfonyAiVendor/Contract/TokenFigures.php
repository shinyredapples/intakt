<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Contract;

// One home for the figures the console and the dashboard show, so both show the same numbers for a round; the prompt holds the cached tokens too, because a cached prompt would otherwise read as a few tokens, and cached is the part of a vendor's cache figure it does not tell apart into reads and writes.
final readonly class TokenFigures
{
    public function __construct(
        public int $prompt,
        public int $cacheRead,
        public int $cacheWrite,
        public int $cached,
        public int $completion,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0, 0, 0, 0);
    }

    // Summed here, never taken from the vendor, because a vendor's own total can be missing (Anthropic reports none).
    public function total(): int
    {
        return $this->prompt + $this->completion;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->prompt + $other->prompt,
            $this->cacheRead + $other->cacheRead,
            $this->cacheWrite + $other->cacheWrite,
            $this->cached + $other->cached,
            $this->completion + $other->completion,
        );
    }
}
