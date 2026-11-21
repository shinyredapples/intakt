<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Kept per quote, because a dropped finding may quote several fragments and each has its own nearest lines.
final readonly class QuoteNearLines
{
    /**
     * @param list<NearLine> $lines
     */
    public function __construct(
        public string $quote,
        public array $lines,
    ) {
    }
}
