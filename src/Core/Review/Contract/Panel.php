<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class Panel
{
    /**
     * @param list<PanelReviewer> $reviewers
     */
    public function __construct(
        public array $reviewers,
        public QuorumPolicy $quorum,
    ) {
        if ([] === $reviewers) {
            throw new PanelWithoutReviewers();
        }

        // Asked of the policy itself, so a panel that could never pass fails when it is configured instead of on every review.
        if (Decision::Approve !== new ChangeDecision()->of($quorum, $this->unanimousApproval())) {
            throw new PanelCannotReachQuorum($quorum);
        }
    }

    /**
     * @return list<PanelVerdict>
     */
    private function unanimousApproval(): array
    {
        $approval = new Verdict(Decision::Approve, [], [], []);

        return array_map(static fn (PanelReviewer $reviewer): PanelVerdict => new PanelVerdict($reviewer->model->tier, $approval, ReviewLane::ofRole($reviewer->role->name)), $this->reviewers);
    }
}
