<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use RuntimeException;

// Thrown only for a tier with substitutes, because a tier without any keeps the wait of its one provider (EveryAccountCoolingDown); the message holds provider names and fixed reasons only, so it is safe to show.
final class EveryProviderOut extends RuntimeException
{
    /**
     * @param non-empty-list<ProviderOutage> $outages
     */
    public function __construct(
        public readonly array $outages,
    ) {
        parent::__construct('Every provider of the tier is out: '.implode(', ', array_map(static fn (ProviderOutage $outage): string => $outage->provider->value.' '.$outage->reason, $outages)).'.');
    }
}
