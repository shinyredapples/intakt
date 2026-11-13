<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use Intakt\Core\Ai\Contract\Tier;

final readonly class RoutingRules
{
    /**
     * @param list<string> $sensitivePaths
     */
    public function __construct(
        public Tier $defaultTier,
        public array $sensitivePaths,
    ) {
    }
}
