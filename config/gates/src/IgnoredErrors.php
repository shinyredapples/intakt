<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class IgnoredErrors
{
    /**
     * @param array<string, int> $counts   by entry, an entry being its identifier, path and message pattern
     * @param list<string>       $problems
     */
    public function __construct(
        public array $counts,
        public array $problems,
    ) {
    }
}
