<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Contract;

final readonly class ProjectEligibilityLabel
{
    public function __construct(
        public string $project,
        public string $label,
    ) {
    }
}
