<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowReviewRecord;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\UseCase\ShowReview\ReviewPage;
use Intakt\Core\Dashboard\UseCase\ShowReview\ReviewView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// The timeline, the reviewers, the tokens and the cost on a view of their own, so the review's main view keeps one message (owner decision for #3360); it takes the same live updates.
final readonly class ShowReviewRecordController
{
    public function __construct(private ReviewPage $page)
    {
    }

    #[Route('/reviews/{round}/record', name: DashboardRoutes::SHOW_REVIEW_RECORD, methods: ['GET'])]
    public function __invoke(Request $request, string $round): Response
    {
        return $this->page->render($request, $round, ReviewView::FullRecord);
    }
}
