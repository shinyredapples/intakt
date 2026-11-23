<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTrends;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\ReviewTrends;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\PublishedBench;
use Intakt\Core\Dashboard\Infrastructure\SampleChangeSource;
use Intakt\Core\Review\Contract\ChangeSource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ShowTrendsController
{
    // The chip's link sets it, so leaving the demo out is an address a reader can keep, not a stored choice.
    public const string LEAVE_OUT_DEMO = 'demo';

    public function __construct(
        private LedgerReviewPages $reviews,
        private SampleChangeSource $sample,
        private LivePageRendering $pages,
        private PublishedBench $bench,
    ) {
    }

    #[Route('/trends', name: DashboardRoutes::SHOW_TRENDS, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $rounds = $this->reviews->everyRound();
        $trends = '0' === $request->query->getString(self::LEAVE_OUT_DEMO) ? ReviewTrends::leavingOutTheDemo($rounds) : ReviewTrends::counting($rounds);

        return new Response($this->pages->render($request, 'dashboard/trends.html.twig', [
            'trends' => $trends,
            'bench' => $this->bench->headline(),
            'sample_can_start' => $this->sample->source() instanceof ChangeSource,
        ]));
    }
}
