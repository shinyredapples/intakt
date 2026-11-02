<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

interface ChangeReviews
{
    /**
     * Queues the next round and returns its id without waiting for the judge.
     *
     * @throws RoundNotNext
     */
    public function start(ChangeReviewRequest $request): string;

    /**
     * @throws RoundNotNext
     */
    public function review(ChangeReviewRequest $request, ReviewProgress $progress = new IgnoredProgress()): ChangeReview;
}
