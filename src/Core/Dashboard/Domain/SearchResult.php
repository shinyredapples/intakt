<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class SearchResult
{
    public function __construct(
        public SearchGroup $group,
        public string $label,
        public string $detail,
        public ?string $href = null,
        public ?SearchAction $action = null,
    ) {
    }

    public function inGroup(SearchGroup $group): self
    {
        return clone ($this, ['group' => $group]);
    }
}
