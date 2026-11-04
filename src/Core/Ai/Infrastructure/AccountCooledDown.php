<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use DateTimeImmutable;

// The payload of an AccountCooledDown ledger row; its keys are the ones rows have always had.
final readonly class AccountCooledDown
{
    public function __construct(
        public string $account,
        public DateTimeImmutable $until,
    ) {
    }
}
