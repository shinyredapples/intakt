<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\RecordDemo;

use DateTimeImmutable;

final readonly class CapturedUpdate
{
    public function __construct(
        public DateTimeImmutable $at,
        public string $topic,
        public string $data,
    ) {
    }
}
