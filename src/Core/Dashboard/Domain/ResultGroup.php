<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class ResultGroup
{
    /**
     * @param non-empty-list<SearchResult> $results
     */
    public function __construct(
        public SearchGroup $group,
        public array $results,
    ) {
    }
}
