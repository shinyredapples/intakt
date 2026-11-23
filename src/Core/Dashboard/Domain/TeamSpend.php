<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Budget\Contract\Cost;

// The paid spend of a pay-per-use account, estimated at the configured prices from the usage the ledger recorded.
final readonly class TeamSpend
{
    public function __construct(
        public Cost $today,
        public Cost $thisWeek,
    ) {
    }

    public static function none(): self
    {
        return new self(Cost::none(), Cost::none());
    }
}
