<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class RoundStart
{
    public function __construct(
        public string $round,
        public string $project,
        public int $reviewers,
        public ?RoundSource $source = null,
    ) {
    }
}
