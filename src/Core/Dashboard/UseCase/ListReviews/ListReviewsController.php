<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ListReviews;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListReviewsController
{
    public function __construct(
        private LedgerReviews $reviews,
        private LedgerFailingHeads $failingHeads,
        private LivePageRendering $pages,
    ) {
    }

    #[Route('/reviews', name: DashboardRoutes::LIST_REVIEWS, methods: ['GET'])]
    // The project only chooses the top bar filter: every row stays on the page, so the filter can switch to any project it offers.
    public function __invoke(Request $request, #[MapQueryParameter] ?string $project = null): Response
    {
        return new Response($this->pages->render($request, 'dashboard/reviews.html.twig', [
            'reviews' => $this->reviews->newestFirst(),
            'failing' => $this->failingHeads->now(),
            'linked_project' => $project,
        ]));
    }
}
