<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Where a part is filed: the project and the repository path it maps to, because an issue number belongs to one repository and a project alias may be remapped before a retry.
#[Exclude]
final readonly class PartPlace
{
    public function __construct(
        public string $project,
        public string $repository,
    ) {
    }
}
