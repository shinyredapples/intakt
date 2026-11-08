<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Decided from the verdicts and the policy alone, because a model's prose must never decide an outcome; in the contract, because the dashboard shows the same outcome from the ledger.
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
