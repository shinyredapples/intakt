<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Decided from the verdicts and the policy alone, because a model's prose must never decide an outcome; in the contract, because the dashboard shows the same outcome from the ledger.
final readonly class ChangeDecision
{
    // The quorum counts the code lane only and every docs vote must approve on top, so the Technical writer blocks like a Code reviewer but its approval never stands in for one.
    /**
     * @param list<PanelVerdict> $votes
     */
    public function of(QuorumPolicy $quorum, array $votes): Decision
    {
        $code = array_values(array_filter($votes, static fn (PanelVerdict $vote): bool => ReviewLane::Code === $vote->lane));
        $docs = array_filter($votes, static fn (PanelVerdict $vote): bool => ReviewLane::Docs === $vote->lane);

        if ($quorum->isMet($code) && array_all($docs, static fn (PanelVerdict $vote): bool => $vote->approves())) {
            return Decision::Approve;
        }

        return array_any($votes, static fn (PanelVerdict $vote): bool => Decision::Changes === $vote->verdict->verdict) ? Decision::Changes : Decision::Blocked;
    }
}
