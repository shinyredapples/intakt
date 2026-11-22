<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardServices;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\Board;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Twig\Environment;

// One worker at a time reads the board and publishes its headline and its In review and Done columns, because two that read and published unordered could leave a line naming a reviewer who already finished, or a card in the wrong column.
final readonly class LiveBoardHeadline
{
    public const string TEMPLATE = 'dashboard/stream/board_headline.stream.html.twig';

    // One for the install, because every round changes the same line and columns on the same topic.
    private const string LOCK = 'dashboard-board-headline';

    // One for the install, because every worker pushes to the same shell topic and only a board that differs from the last pushed one is pushed again.
    private const string LAST_PUSHED = 'last-pushed-board';

    public function __construct(
        private LedgerReviewPages $pages,
        private Environment $twig,
        private LiveUpdates $updates,
        private LockFactory $locks,
        #[Autowire(service: DashboardServices::BOARD_HEADLINES)]
        private CacheItemPoolInterface $lastPushed,
        private LoggerInterface $logger,
    ) {
    }

    public function publishLatest(Uuid $round): void
    {
        try {
            $this->publishedInTurn();
            // Boundary: logs a failed headline and swallows it, because the board is a view and a round update must never change a verdict or a round.
        } catch (Throwable $failure) {
            $this->logger->warning('The board headline was not published.', ['round' => $round->toRfc4122(), 'exception' => $failure]);
        }
    }

    // Without a round, because removing the demo project's rows follows no round and can leave the board empty again, which brings its octopus back (#2437).
    public function publishTheBoard(): void
    {
        try {
            $this->publishedInTurn();
            // Boundary: logs a failed headline and swallows it, because the board is a view and removing a project's rows must not fail over it.
        } catch (Throwable $failure) {
            $this->logger->warning('The board headline was not published.', ['exception' => $failure]);
        }
    }

    private function publishedInTurn(): void
    {
        $lock = $this->locks->createLock(self::LOCK);

        // Tried without waiting, because the worker holding the lock reads the board again after it lets go, and so publishes what this one would have found.
        while ($lock->acquire(false)) {
            try {
                $published = $this->publishedOnce();
            } finally {
                $lock->release();
            }

            if ($this->signatureOf($this->pages->board()) === $published) {
                return;
            }
        }
    }

    // Read and written under the lock, so no other worker pushes between the read of the last pushed board and the record of this one.
    private function publishedOnce(): string
    {
        $lastPushed = $this->lastPushed->getItem(self::LAST_PUSHED);
        $board = $this->pages->board();
        $signature = $this->signatureOf($board);

        if ($signature !== $lastPushed->get()) {
            $this->updates->publish(new LiveEvent(DashboardTopics::SHELL, $this->streamOf($board)));
            $this->lastPushed->save($lastPushed->set($signature));
        }

        return $signature;
    }

    // The boards are compared, not the rendered streams, because a rendered term carries a fresh random id each time and two renders of one board never match; hashed, so the cache keeps a short value however many cards the board has.
    private function signatureOf(Board $board): string
    {
        return hash('xxh128', serialize($board));
    }

    private function streamOf(Board $board): string
    {
        return $this->twig->render(self::TEMPLATE, ['headline' => $board->headline(), 'board' => $board]);
    }
}
