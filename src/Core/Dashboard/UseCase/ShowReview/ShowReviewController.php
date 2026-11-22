<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowReview;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ShowReviewController
{
    public function __construct(private ReviewPage $page)
    {
    }

    #[Route('/reviews/{round}', name: DashboardRoutes::SHOW_REVIEW, methods: ['GET'])]
    public function __invoke(Request $request, string $round): Response
    {
        return $this->page->render($request, $round, ReviewView::Main);
    }
}
