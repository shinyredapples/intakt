<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowBoard;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ShowBoardController
{
    private const string SETTING_UP = 'Intakt is still setting up its database. Reload this page in a moment.';

    public function __construct(
        private LedgerReviewPages $reviewPages,
        private LivePageRendering $pages,
    ) {
    }

    #[Route('/', name: DashboardRoutes::SHOW_BOARD, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        try {
            $board = $this->reviewPages->board();
        } catch (TableNotFoundException) {
            // The instance check and a container that is still migrating request `/` before the ledger's table exists, and a 500 there reads as a broken install.
            return new Response(self::SETTING_UP, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return new Response($this->pages->render($request, 'dashboard/board.html.twig', [
            'board' => $board,
            'columns' => BoardColumn::cases(),
        ]));
    }
}
