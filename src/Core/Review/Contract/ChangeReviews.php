<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

interface ChangeReviews
{
    /**
     * @throws RoundNotNext
     */
    public function review(ChangeReviewRequest $request): ChangeReview;
}
