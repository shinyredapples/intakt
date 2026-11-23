<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowBoard;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\SampleChangeSource;
use Intakt\Core\Dashboard\Infrastructure\WaitingCount;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Watcher\Contract\FailingMain;
use Intakt\Core\Watcher\Contract\FailingMains;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ShowBoardController
{
    private const string BOARD_FLAG = 'board';

    public function __construct(
        private Ledger $ledger,
        private LedgerReviewPages $reviewPages,
        private LedgerFailingHeads $failingHeads,
        private FailingMains $failingMains,
        private Redactor $redactor,
        private LivePageRendering $pages,
        private SampleChangeSource $sample,
        private UrlGeneratorInterface $urls,
        private WaitingCount $waiting,
        private ClockInterface $clock,
    ) {
    }

    // One read of the ledger for the cards and the banners, so a line the watcher writes meanwhile cannot show a board from two moments.
    #[Route('/', name: DashboardRoutes::SHOW_BOARD, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $events = $this->ledger->readInOrder();
        $board = $this->reviewPages->boardOf($events);

        // Only on a demo install, because an install without the sample has nothing on the first-run page to start and keeps the board's own opening.
        if ($board->hasNoReview() && $this->sample->hasRepository()) {
            return $this->variesByDevice($this->redirectTo(DashboardRoutes::FIRST_RUN));
        }

        if ($this->landsOnWaiting($request)) {
            return $this->variesByDevice($this->quietRoundOf($board) ?? $this->redirectTo(DashboardRoutes::LIST_WAITING));
        }

        // One reading for the Needs you card and the You badge, so a notice that clears meanwhile cannot show one without the other.
        $waiting = $this->waiting->stillRunningOldestFirst();

        return $this->variesByDevice(new Response($this->pages->render($request, 'dashboard/board.html.twig', [
            'board' => $board,
            'failing' => array_map($this->redacted(...), $this->failingHeads->in($events)),
            'failing_mains' => array_map($this->redactedMain(...), $this->failingMains->in($events)),
            'working' => $board->whoIsWorking(),
            'waiting' => $waiting,
            'waiting_listed' => count($waiting),
            'now' => $this->clock->now(),
            'columns' => $board->shownColumns(),
            'coming_next' => $board->comingNext(),
        ])));
    }

    /**
     * @param array<string, string> $parameters
     */
    private function redirectTo(string $route, array $parameters = []): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate($route, $parameters), Response::HTTP_SEE_OTHER);
    }

    // Every answer of the root address varies by device, so a shared cache never hands a desktop's board to a phone or a phone's redirect to a desktop.
    private function variesByDevice(Response $response): Response
    {
        return $response->setVary('Sec-CH-UA-Mobile, User-Agent');
    }

    // A phone opens on what only the owner can act on, or on its newest review while the install is quiet; the Board flag of the dashboard's own Board links skips it, so a tap on Board still shows the board.
    private function landsOnWaiting(Request $request): bool
    {
        if ($request->query->has(self::BOARD_FLAG)) {
            return false;
        }

        return '?1' === $request->headers->get('Sec-CH-UA-Mobile')
            || str_contains($request->headers->get('User-Agent') ?? '', 'Mobi');
    }

    // A quiet install (nothing waits on the owner, nothing runs) opens its newest review on a phone, because an empty Waiting on you shows nothing of the product (#3142); the notices are read last, so a busy phone is not sent to the database twice.
    private function quietRoundOf(Board $board): ?RedirectResponse
    {
        $newest = $board->newestReviewAtRest();
        if (!$newest instanceof Review || [] !== $this->waiting->stillRunningOldestFirst()) {
            return null;
        }

        return $this->redirectTo(DashboardRoutes::SHOW_REVIEW, ['round' => $newest->latest()->round]);
    }

    // Redacted because the cause quotes an exception, which can carry a credential, as the status report does.
    private function redacted(FailingHead $head): FailingHead
    {
        return $head->withCause($this->redactor->redact($head->fault->cause));
    }

    private function redactedMain(FailingMain $main): FailingMain
    {
        return new FailingMain($main->project, $main->main, $main->toolFingerprint, $this->redactor->redact($main->reason));
    }
}
