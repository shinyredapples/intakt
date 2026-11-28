<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowReviewRecord;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\UseCase\ShowReview\ReviewView;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ShowReviewRecordController
{
    public function __construct(
        private ShowReviewController $review,
    ) {
    }

    // The timeline, the reviewers, the tokens and the cost on a view of their own, so the review's main view keeps one message (owner decision for #3360); it takes the same live updates.
    #[Route('/reviews/{round}/record', name: DashboardRoutes::SHOW_REVIEW_RECORD, methods: ['GET'])]
    public function __invoke(Request $request, string $round): Response
    {
        return $this->review->show($request, $round, ReviewView::FullRecord);
    }
}
