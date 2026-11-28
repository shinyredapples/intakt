<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Twig\Environment;

// One worker at a time reads and pushes the Trends figures and Up next, because two that read and pushed unordered could leave an open page counting a round fewer than the ledger holds (D9 of #2864).
final readonly class LiveTrends
{
    public const string TEMPLATE = 'dashboard/stream/trends.stream.html.twig';

    // One for the install, because every round changes the same figures on the same topic.
    private const string LOCK = 'dashboard-trends';

    public function __construct(
        private LedgerTrends $trends,
        private Environment $twig,
        private LiveUpdates $updates,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    public function publishLatest(Uuid $round): void
    {
        try {
            $this->publishedInTurn();
            // Boundary: logs failed figures and swallows them, because Trends is a view and a round update must never change a verdict or a round.
        } catch (Throwable $failure) {
            $this->logger->warning('The trends were not published.', ['round' => $round->toRfc4122(), 'exception' => $failure]);
        }
    }

    // Without a round, because a replayed row is no round update and the demo must still move the figures row by row.
    public function publishTheTrends(): void
    {
        try {
            $this->publishedInTurn();
            // Boundary: logs failed figures and swallows them, because a replay must not stop over a view.
        } catch (Throwable $failure) {
            $this->logger->warning('The trends were not published.', ['exception' => $failure]);
        }
    }

    private function publishedInTurn(): void
    {
        $lock = $this->locks->createLock(self::LOCK);

        // Tried without waiting, because the worker holding the lock reads the ledger again after it lets go, and so pushes what this one would have found.
        while ($lock->acquire(false)) {
            try {
                $published = $this->publishedOnce();
            } finally {
                $lock->release();
            }

            if ($this->stream() === $published) {
                return;
            }
        }
    }

    private function publishedOnce(): string
    {
        $stream = $this->stream();
        $this->updates->publish(new LiveEvent(DashboardTopics::SHELL, $stream));

        return $stream;
    }

    // Compared as rendered, because the Trends partials carry no random id, so two renders of one ledger are the same text.
    private function stream(): string
    {
        $reading = $this->trends->read();

        return $this->twig->render(self::TEMPLATE, [
            'variants' => [$reading->counting(), $reading->leavingOutTheDemo()],
            'up_next' => $reading->upNext,
            'bench' => $reading->bench,
        ]);
    }
}
