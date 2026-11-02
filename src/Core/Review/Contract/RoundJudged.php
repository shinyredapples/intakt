<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Announced after the judge, because the checkout the reviewers read is not needed past it and only the module that made it can remove it.
final readonly class RoundJudged
{
    public function __construct(
        public string $roundId,
        public string $checkoutRoot,
    ) {
    }
}
