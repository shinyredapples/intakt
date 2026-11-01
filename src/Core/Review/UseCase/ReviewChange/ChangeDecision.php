<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\QuorumPolicy;

// Decided from the verdicts and the policy alone, because a model's prose must never decide an outcome.
final readonly class ChangeDecision
{
    /**
     * @param list<PanelVerdict> $votes
     */
    public function of(QuorumPolicy $quorum, array $votes): Decision
    {
        if ($quorum->isMet($votes)) {
            return Decision::Approve;
        }

        return array_any($votes, static fn (PanelVerdict $vote): bool => Decision::Changes === $vote->verdict->verdict) ? Decision::Changes : Decision::Blocked;
    }
}
