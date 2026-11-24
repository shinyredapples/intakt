<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Budget\Contract\Cost;

// Estimated at the configured prices, because the ledger records tokens and no vendor bill reaches Intakt.
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
