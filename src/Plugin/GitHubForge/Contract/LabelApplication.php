<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class LabelApplication
{
    public function __construct(
        public string $label,
        public string $appliedBy,
    ) {
    }
}
