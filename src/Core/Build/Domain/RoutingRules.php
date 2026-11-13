<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Build\Contract\Setback;

final readonly class RoutingRules
{
    /**
     * @param list<string>  $sensitivePaths
     * @param list<Setback> $escalateOn
     */
    public function __construct(
        public Tier $defaultTier,
        public array $sensitivePaths,
        public array $escalateOn,
    ) {
    }
}
