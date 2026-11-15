<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Domain;

use Intakt\Core\Review\Contract\Severity;

final readonly class PlantedDefect
{
    public function __construct(
        public string $id,
        public string $file,
        public int $from,
        public int $to,
        public string $description,
        public Severity $severity,
    ) {
    }
}
