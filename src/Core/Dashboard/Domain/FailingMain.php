<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class FailingMain
{
    public function __construct(
        public string $project,
        public string $reason,
    ) {
    }
}
