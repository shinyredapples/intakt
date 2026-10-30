<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class ReviewerRole
{
    /**
     * @param non-empty-string $name
     * @param non-empty-string $prompt
     */
    public function __construct(
        public string $name,
        public string $prompt,
    ) {
    }
}
