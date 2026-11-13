<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowBoard;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Platform\Contract\Redactor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ShowBoardController
{
    public function __construct(
        private Ledger $ledger,
        private LedgerReviewPages $reviewPages,
        private LedgerFailingHeads $failingHeads,
        private Redactor $redactor,
        private LivePageRendering $pages,
    ) {
    }

    // One read of the ledger for the cards and the banners, so a line the watcher writes meanwhile cannot show a board from two moments.
    #[Route('/', name: DashboardRoutes::SHOW_BOARD, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $events = $this->ledger->readInOrder();
        $board = $this->reviewPages->boardOf($events);

        return new Response($this->pages->render($request, 'dashboard/board.html.twig', [
            'board' => $board,
            'failing' => array_map($this->redacted(...), $this->failingHeads->in($events)),
            'working' => $board->whoIsWorking(),
            'columns' => BoardColumn::cases(),
        ]));
    }

    // Redacted because the cause quotes an exception, which can carry a credential, as the status report does.
    private function redacted(FailingHead $head): FailingHead
    {
        return $head->withCause($this->redactor->redact($head->fault->cause));
    }
}
