<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowReview;

use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Intakt\Core\Review\Contract\SessionSummaries;
use Intakt\Core\Review\Contract\SessionSummary;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// One rendering for the review's two routes, because a route is named after its use-case folder alone and the Full record has a folder of its own.
final readonly class ReviewPage
{
    public function __construct(
        private LedgerReviewPages $reviews,
        private LivePageRendering $pages,
        private SessionSummaries $summaries,
    ) {
    }

    public function render(Request $request, string $round, ReviewView $view): Response
    {
        $review = $this->reviews->reviewOf($round);

        if (!$review instanceof Review) {
            throw new NotFoundHttpException('The ledger holds no review with this round.');
        }

        return new Response($this->pages->render($request, 'dashboard/review.html.twig', [
            'record' => ReviewView::FullRecord === $view,
            'review' => $review,
            'round' => $round,
            'topics' => $this->unfinishedTopicsOf($review),
            'summary' => $this->summaryOfTheActiveRound($review),
        ]));
    }

    /**
     * @return list<string>
     */
    private function unfinishedTopicsOf(Review $review): array
    {
        $unfinished = array_filter($review->rounds, static fn (ReviewedRound $reviewed): bool => !$reviewed->isJudged() && !$reviewed->hasEnded());

        return array_values(array_map(static fn (ReviewedRound $reviewed): string => MercureRoundUpdates::topicOf($reviewed->round), $unfinished));
    }

    private function summaryOfTheActiveRound(Review $review): ?SessionSummary
    {
        $active = $review->activeRound();

        return $active instanceof ReviewedRound ? $this->summaries->ofRound($active->round) : null;
    }
}
