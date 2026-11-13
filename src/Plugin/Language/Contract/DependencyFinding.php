<?php

declare(strict_types=1);

namespace Intakt\Plugin\Language\Contract;

// One reason a dependency change needs the owner: the package or setting it concerns, and why.
final readonly class DependencyFinding
{
    public function __construct(
        public string $subject,
        public string $reason,
    ) {
    }
}
