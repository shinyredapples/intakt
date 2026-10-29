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
    }
}
