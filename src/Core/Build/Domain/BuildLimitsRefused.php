<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use InvalidArgumentException;

// Its own class, because the container build catches exactly this refusal and lets any other failure pass.
final class BuildLimitsRefused extends InvalidArgumentException
{
    public static function belowOne(int $allowanceTokens, int $idleSeconds, int $ceilingTokens, int $taskTotalTokens): self
    {
        return new self(sprintf('Every build limit must be at least 1, not allowance %d, idle %d s, ceiling %d and per-task total %d.', $allowanceTokens, $idleSeconds, $ceilingTokens, $taskTotalTokens));
    }

    public static function ceilingBelowAllowance(int $ceilingTokens, int $allowanceTokens): self
    {
        return new self(sprintf('The runaway ceiling of %d tokens is below the allowance of %d tokens.', $ceilingTokens, $allowanceTokens));
    }
}
