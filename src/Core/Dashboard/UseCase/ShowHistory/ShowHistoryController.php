<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowHistory;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\History;
use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Ledger\Contract\Ledger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ShowHistoryController
{
    public function __construct(
        private Ledger $ledger,
        private LivePageRendering $pages,
        private FilterProjects $filter,
    ) {
    }

    #[Route('/history', name: DashboardRoutes::SHOW_HISTORY, methods: ['GET'])]
    // The project only chooses the top bar filter: every row stays on the page, so the filter can switch to any project it offers.
    public function __invoke(Request $request, #[MapQueryParameter] ?string $project = null): Response
    {
        $history = History::of($this->ledger->readInOrder());

        return new Response($this->pages->render($request, 'dashboard/history.html.twig', [
            'history' => $history,
            'linked_project' => $project,
            'project_names' => $this->filter->namesWith($history->projects()),
        ]));
    }
}
