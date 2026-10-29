<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

// Names one call across its ledger rows; a reservation that is neither settled nor left unsettled leaves a started row with no end.
final readonly class UsageReservation
{
    public function __construct(public string $call)
    {
    }
}
