<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Grouped, because the four values are one pull request and travel together through every forge.
final readonly class ProposedChange
{
    public function __construct(
        public string $project,
        public string $branch,
        public string $base,
        public ChangeText $text,
    ) {
    }
}
