<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTrends;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewTrends;
use Intakt\Core\Dashboard\Infrastructure\LedgerQueuedRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\PublishedBench;
use Intakt\Core\Dashboard\Infrastructure\SampleChangeSource;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Review\Contract\ChangeSource;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ShowTrendsController
{
    // The chip's link sets it, so leaving the demo out is an address a reader can keep, not a stored choice.
    public const string LEAVE_OUT_DEMO = 'demo';

    public function __construct(
        private Ledger $ledger,
        private LedgerReviewPages $reviews,
        private LedgerQueuedRounds $queued,
        private SampleChangeSource $sample,
        private LivePageRendering $pages,
        private PublishedBench $bench,
        private ClockInterface $clock,
    ) {
    }

    // One read of the ledger and one set of reviews for the figures and Up next, so a round that starts meanwhile cannot show in one and not the other, and no round's replay mark is asked for twice.
    #[Route('/trends', name: DashboardRoutes::SHOW_TRENDS, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $events = $this->ledger->readInOrder();
        $reviews = $this->reviews->reviewsOf($events);
        $rounds = array_merge([], ...array_map(static fn (Review $review): array => $review->rounds, $reviews));
        $trends = '0' === $request->query->getString(self::LEAVE_OUT_DEMO) ? ReviewTrends::leavingOutTheDemo($rounds) : ReviewTrends::counting($rounds);

        return new Response($this->pages->render($request, 'dashboard/trends.html.twig', [
            'trends' => $trends,
            'up_next' => new Board($this->clock->now(), $reviews)->upNext($this->queued->in($events)),
            'bench' => $this->bench->headline(),
            'sample_can_start' => $this->sample->source() instanceof ChangeSource,
        ]));
    }
}
