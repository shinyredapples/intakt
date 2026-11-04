<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;

final readonly class HeadFault
{
    public function __construct(
        public string $cause,
        public int $failures,
        public DateTimeImmutable $retryAt,
    ) {
    }
}
