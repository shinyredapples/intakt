<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class TierLine
{
    // The provider is "-" when the tier resolves to no model, so the page reads like intakt:ai:tiers.
    public function __construct(
        public string $tier,
        public string $provider,
        public string $modelOrProblem,
    ) {
    }
}
