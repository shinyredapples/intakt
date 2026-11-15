<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\RoundJudged;
use Intakt\Core\Review\Contract\RoundOpened;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Lock\LockFactory;
use Throwable;
use Twig\Environment;

// On a round's start and its judgement, because those are the moments a lane appears on the board or leaves it.
final readonly class PublishBoardHeadline
{
    public const string TEMPLATE = 'dashboard/stream/board_headline.stream.html.twig';

    // One for the install, because every worker publishes the same headline on the same topic.
    private const string LOCK = 'dashboard-board-headline';

    public function __construct(
        private LedgerReviewPages $pages,
        private Environment $twig,
        private LiveUpdates $updates,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    #[AsCommandHandler]
    public function opened(RoundOpened $opened): void
    {
        $this->publishLatest($opened->round);
    }

    #[AsEventListener]
    public function judged(RoundJudged $judged): void
    {
        $this->publishLatest($judged->roundId);
    }

    private function publishLatest(string $round): void
    {
        $lock = $this->locks->createLock(self::LOCK);

        try {
            // Waited for, because the ledger is read under the lock: the last publication then holds the newest read, never an older one that finished later.
            $lock->acquire(true);
            $this->publish();
        } catch (Throwable $failure) {
            // Swallowed after a log, because the round is already committed and a live update must never change it.
            $this->logger->warning('The board headline was not published.', ['round' => $round, 'exception' => $failure]);
        } finally {
            $lock->release();
        }
    }

    private function publish(): void
    {
        $lead = $this->pages->board()->headline();

        $this->updates->publish(new LiveEvent(DashboardTopics::SHELL, $this->twig->render(self::TEMPLATE, ['lead' => $lead])));
    }
}
