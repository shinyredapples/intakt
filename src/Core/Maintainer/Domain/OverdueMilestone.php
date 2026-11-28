<?php

declare(strict_types=1);

namespace Intakt\Core\Maintainer\Domain;

use DateTimeImmutable;

final readonly class OverdueMilestone
{
    public function __construct(
        public DateTimeImmutable $due,
        public int $openTasks,
    ) {
    }
}
