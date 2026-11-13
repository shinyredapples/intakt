<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Contract\TierReason;
use Intakt\Core\Review\Contract\PathGlob;

final readonly class ModelRouting
{
    public function choose(RoutingRules $rules, BuildFacts $facts): TierChoice
    {
        // A sensitive path comes first because a safety rule must hold whatever the label says; the label then beats the default.
        if ($this->touchesSensitivePath($rules, $facts)) {
            return new TierChoice(Tier::Strong, TierReason::SensitivePath);
        }

        if ($facts->labelledTier instanceof Tier) {
            return new TierChoice($facts->labelledTier, TierReason::Label);
        }

        return new TierChoice($rules->defaultTier, TierReason::Default);
    }

    private function touchesSensitivePath(RoutingRules $rules, BuildFacts $facts): bool
    {
        foreach ($rules->sensitivePaths as $glob) {
            $pattern = new PathGlob($glob);

            if ([] !== array_filter($facts->paths, $pattern->matches(...))) {
                return true;
            }
        }

        return false;
    }
}
