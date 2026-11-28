<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class BuildLimits
{
    public function __construct(
        public int $allowanceTokens,
        public int $idleSeconds,
        public int $ceilingTokens,
        public int $taskTotalTokens,
    ) {
        if (min($allowanceTokens, $idleSeconds, $ceilingTokens, $taskTotalTokens) < 1) {
            throw BuildLimitsRefused::belowOne($allowanceTokens, $idleSeconds, $ceilingTokens, $taskTotalTokens);
        }

        if ($ceilingTokens < $allowanceTokens) {
            throw BuildLimitsRefused::ceilingBelowAllowance($ceilingTokens, $allowanceTokens);
        }
    }
}
