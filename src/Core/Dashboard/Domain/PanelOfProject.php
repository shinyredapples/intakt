<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded from the container, because the setup steps build one per project and page.
#[Exclude]
final readonly class PanelOfProject
{
    public function __construct(
        public string $project,
        public string $description,
        public bool $configured = true,
        public ?string $mainBootFailure = null,
    ) {
    }
}
