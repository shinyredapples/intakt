<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

interface ChangeReviews
{
    // Returns the round id at once, because a web request cannot wait for the judge.
    /**
     * @throws RoundNotNext
     */
    public function start(ChangeReviewRequest $request): string;

    /**
     * @throws RoundNotNext
     */
    public function review(ChangeReviewRequest $request, ReviewProgress $progress = new IgnoredProgress()): ChangeReview;
}
