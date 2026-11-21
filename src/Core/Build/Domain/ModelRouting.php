<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\Setback;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Contract\TierReason;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\PathGlob;

final readonly class ModelRouting
{
    public function choose(RoutingRules $rules, BuildFacts $facts): TierChoice
    {
        // A sensitive path comes first because a safety rule must hold whatever the label says; the label then beats the default.
        if ($this->touchesSensitivePath($rules, $facts)) {
            return new TierChoice(Tier::Strong, TierReason::SensitivePath);
        }

        // Not one of the setbacks escalate_on lists, because the escalation review's confirmation is the design's rule for a recurring P1, never a project's choice; a label that already names strong keeps its reason.
        if (EscalationOutcome::Confirmed === $facts->escalation && Tier::Strong !== $facts->labelledTier) {
            return new TierChoice(Tier::Strong, TierReason::ConfirmedEscalation);
        }

        // A setback only raises the tier: a label that already names strong keeps its reason, a cheaper label is overridden.
        if ($this->escalates($rules, $facts) && Tier::Strong !== $facts->labelledTier) {
            return new TierChoice(Tier::Strong, TierReason::Setback);
        }

        if ($facts->labelledTier instanceof Tier) {
            return new TierChoice($facts->labelledTier, TierReason::Label);
        }

        return new TierChoice($rules->defaultTier, TierReason::Default);
    }

    private function escalates(RoutingRules $rules, BuildFacts $facts): bool
    {
        return $facts->setback instanceof Setback && in_array($facts->setback, $rules->escalateOn, true);
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
