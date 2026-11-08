<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ListReviews;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final readonly class ListReviewsController
{
    public function __construct(
        private LedgerReviews $reviews,
        private LedgerFailingHeads $failingHeads,
        private Environment $twig,
    ) {
    }

    #[Route('/reviews', name: DashboardRoutes::LIST_REVIEWS, methods: ['GET'])]
    /**
     * The project only chooses the top bar filter: every row stays on the page, so the filter can switch to any project it offers.
     */
    public function __invoke(#[MapQueryParameter] ?string $project = null): Response
    {
        return new Response($this->twig->render('dashboard/reviews.html.twig', [
            'reviews' => $this->reviews->newestFirst(),
            'failing' => $this->failingHeads->now(),
            'linked_project' => $project,
        ]));
    }
}
