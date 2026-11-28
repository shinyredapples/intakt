<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTrends;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\LedgerTrends;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
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
        private LedgerTrends $trends,
        private SampleChangeSource $sample,
        private LivePageRendering $pages,
    ) {
    }

    #[Route('/trends', name: DashboardRoutes::SHOW_TRENDS, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $reading = $this->trends->read();

        return new Response($this->pages->render($request, 'dashboard/trends.html.twig', [
            'trends' => '0' === $request->query->getString(self::LEAVE_OUT_DEMO) ? $reading->leavingOutTheDemo() : $reading->counting(),
            'up_next' => $reading->upNext,
            'bench' => $reading->bench,
            'sample_can_start' => $this->sample->source() instanceof ChangeSource,
        ]));
    }
}
