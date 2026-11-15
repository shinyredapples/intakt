<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class PanelOfProject
{
    public function __construct(
        public string $project,
        public string $description,
    ) {
    }
}
