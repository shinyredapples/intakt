<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use DateTimeImmutable;

final readonly class AccountErrorReported
{
    public function __construct(
        public string $account,
        public DateTimeImmutable $reportedAt,
    ) {
    }
}
