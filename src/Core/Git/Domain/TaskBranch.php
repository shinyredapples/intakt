<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Domain;

final readonly class TaskBranch
{
    public function __construct(
        public string $name,
        public ?SourceLine $source = null,
    ) {
    }
}
