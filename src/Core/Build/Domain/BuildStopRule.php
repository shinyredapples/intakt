<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use NoDiscard;
use Psr\Clock\ClockInterface;

// Progress, not spend, ends a build (docs/design/05-architecture/06-budget-and-model-planner.md, "Allowance per task").
final readonly class BuildStopRule
{
    public function __construct(private ClockInterface $clock)
    {
    }

    #[NoDiscard]
    public function startWatching(BuildLimits $limits, BuildProgress $progress): BuildWatch
    {
        return new BuildWatch($limits, $progress, $this->clock->now());
    }

    #[NoDiscard]
    public function observe(BuildWatch $watch, BuildProgress $progress): BuildWatch
    {
        return $watch->seeing($progress, $this->clock->now());
    }

    // Null lets the build go on. The order decides which cause is recorded when several apply at once.
    #[NoDiscard]
    public function stopCause(BuildWatch $watch): ?BuildStopCause
    {
        return match (true) {
            $this->reachedTheTaskTotal($watch) => BuildStopCause::PerTaskTotal,
            $this->reachedTheCeiling($watch) => BuildStopCause::RunawayCeiling,
            $this->isUnderItsAllowance($watch), $watch->latest->checkRunning => null,
            $this->isIdlePastTheLimit($watch) => BuildStopCause::NoProgress,
            default => null,
        };
    }

    private function reachedTheTaskTotal(BuildWatch $watch): bool
    {
        return $watch->latest->taskTokensUsed >= $watch->limits->taskTotalTokens;
    }

    private function reachedTheCeiling(BuildWatch $watch): bool
    {
        return $watch->latest->tokensUsed >= $watch->limits->ceilingTokens;
    }

    private function isUnderItsAllowance(BuildWatch $watch): bool
    {
        return $watch->latest->tokensUsed < $watch->limits->allowanceTokens;
    }

    private function isIdlePastTheLimit(BuildWatch $watch): bool
    {
        $idleSeconds = $this->clock->now()->getTimestamp() - $watch->lastProgressAt->getTimestamp();

        return $idleSeconds >= $watch->limits->idleSeconds;
    }
}
