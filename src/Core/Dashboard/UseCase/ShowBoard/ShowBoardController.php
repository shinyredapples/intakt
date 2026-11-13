<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowBoard;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ShowBoardController
{
    public function __construct(
        private LedgerReviewPages $reviewPages,
        private LedgerFailingHeads $failingHeads,
        private LivePageRendering $pages,
    ) {
    }

    #[Route('/', name: DashboardRoutes::SHOW_BOARD, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $board = $this->reviewPages->board();

        return new Response($this->pages->render($request, 'dashboard/board.html.twig', [
            'failing' => $this->failingHeads->now(),
            'board' => $board,
            'working' => $board->whoIsWorking(),
            'columns' => BoardColumn::cases(),
        ]));
    }
}
