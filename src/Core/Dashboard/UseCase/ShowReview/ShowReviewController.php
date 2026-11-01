<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowReview;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final readonly class ShowReviewController
{
    public function __construct(
        private LedgerReviewPages $reviews,
        private Environment $twig,
    ) {
    }

    #[Route('/reviews/{round}', name: DashboardRoutes::SHOW_REVIEW, methods: ['GET'])]
    public function __invoke(string $round): Response
    {
        $review = $this->reviews->reviewOf($round);

        if (!$review instanceof Review) {
            throw new NotFoundHttpException('The ledger holds no review with this round.');
        }

        return new Response($this->twig->render('dashboard/review.html.twig', ['review' => $review, 'round' => $round]));
    }
}
