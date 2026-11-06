<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class IgnoredErrors
{
    /**
     * @param array<string, int> $counts
     * @param list<string>       $problems
     */
    public function __construct(
        public array $counts,
        public array $problems,
    ) {
    }
}
