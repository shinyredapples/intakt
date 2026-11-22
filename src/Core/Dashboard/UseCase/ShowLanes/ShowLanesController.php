<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowLanes;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Ledger\Contract\Ledger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// The Status page has an address of its own, because /status is the JSON report intakt:status matches byte for byte.
final readonly class ShowLanesController
{
    public function __construct(
        private Ledger $ledger,
        private LedgerReviewPages $reviewPages,
        private LivePageRendering $pages,
    ) {
    }

    #[Route('/lanes', name: DashboardRoutes::SHOW_LANES, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $board = $this->reviewPages->boardOf($this->ledger->readInOrder());

        return new Response($this->pages->render($request, 'dashboard/lanes.html.twig', [
            'working' => $board->whoIsWorking(),
            'nobody_working' => $board->nobodyWorkingLine(),
            'rail_section' => 'board',
        ]));
    }
}
