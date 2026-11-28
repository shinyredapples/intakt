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
            throw new BuildLimitsRefused(sprintf('Every build limit must be at least 1, not allowance %d, idle %d s, ceiling %d and per-task total %d.', $allowanceTokens, $idleSeconds, $ceilingTokens, $taskTotalTokens));
        }

        if ($ceilingTokens < $allowanceTokens) {
            throw new BuildLimitsRefused(sprintf('The runaway ceiling of %d tokens is below the allowance of %d tokens.', $ceilingTokens, $allowanceTokens));
        }
    }
}
