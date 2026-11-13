<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;

final readonly class Lane
{
    public function __construct(
        public string $reviewer,
        public string $role,
        public string $tier,
        public string $project,
        public string $change,
        public ?int $round,
        public DateTimeImmutable $since,
    ) {
    }
}
