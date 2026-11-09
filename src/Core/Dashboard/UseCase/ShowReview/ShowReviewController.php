<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowReview;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewState;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ShowReviewController
{
    public function __construct(
        private LedgerReviewPages $reviews,
        private LivePageRendering $pages,
    ) {
    }

    #[Route('/reviews/{round}', name: DashboardRoutes::SHOW_REVIEW, methods: ['GET'])]
    public function __invoke(Request $request, string $round): Response
    {
        $review = $this->reviews->reviewOf($round);

        if (!$review instanceof Review) {
            throw new NotFoundHttpException('The ledger holds no review with this round.');
        }

        return new Response($this->pages->render($request, 'dashboard/review.html.twig', ['review' => $review, 'round' => $round, 'topics' => $this->unfinishedTopicsOf($review)]));
    }

    /**
     * @return list<string>
     */
    private function unfinishedTopicsOf(Review $review): array
    {
        $unfinished = array_filter($review->rounds, static fn (ReviewedRound $reviewed): bool => ReviewState::Judged !== $reviewed->state);

        return array_values(array_map(static fn (ReviewedRound $reviewed): string => MercureRoundUpdates::topicOf($reviewed->round), $unfinished));
    }
}
