<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\QuorumPolicy;

// Plain code from the verdicts and the policy, never from a model's prose; a reviewer that gave no verdict votes as blocked.
final readonly class ChangeDecision
{
    /**
     * @param list<PanelVerdict> $votes One per reviewer of the panel
     */
    public function of(QuorumPolicy $quorum, array $votes): Decision
    {
        if ($quorum->isMet($votes)) {
            return Decision::Approve;
        }

        return array_any($votes, static fn (PanelVerdict $vote): bool => Decision::Changes === $vote->verdict->verdict) ? Decision::Changes : Decision::Blocked;
    }
}
