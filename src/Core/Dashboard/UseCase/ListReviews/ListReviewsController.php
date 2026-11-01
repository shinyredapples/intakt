<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ListReviews;

use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final readonly class ListReviewsController
{
    public function __construct(
        private LedgerReviews $reviews,
        private Environment $twig,
    ) {
    }

    #[Route('/reviews', name: 'dashboard_list_reviews', methods: ['GET'])]
    public function __invoke(#[MapQueryParameter] ?string $project = null): Response
    {
        $reviews = array_values(array_filter(
            $this->reviews->newestFirst(),
            static fn (ReviewSummary $review): bool => null === $project || $review->project === $project,
        ));

        return new Response($this->twig->render('dashboard/reviews.html.twig', ['reviews' => $reviews, 'project' => $project]));
    }
}
