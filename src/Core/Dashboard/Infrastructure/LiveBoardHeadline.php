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

// One worker at a time reads the board and publishes its headline, because two that read and published unordered could leave a line naming a reviewer who already finished.
final readonly class LiveBoardHeadline
{
    public const string TEMPLATE = 'dashboard/stream/board_headline.stream.html.twig';

    // One for the install, because every round changes the same line on the same topic.
    private const string LOCK = 'dashboard-board-headline';

    public function __construct(
        private LedgerReviewPages $pages,
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
        } catch (Throwable $failure) {
            // Swallowed after a log, because the line is a view and a round update must never change a verdict or a round.
            $this->logger->warning('The board headline was not published.', ['round' => $round->toRfc4122(), 'exception' => $failure]);
        }
    }

    private function publishedInTurn(): void
    {
        $lock = $this->locks->createLock(self::LOCK);
        $published = null;

        // Tried without waiting, because the worker holding the lock reads the board again after it lets go, and so publishes what this one would have found.
        while ($lock->acquire(false)) {
            try {
                $published = $this->publishedOnce($published);
            } finally {
                $lock->release();
            }

            if ($this->streamOf() === $published) {
                return;
            }
        }
    }

    private function publishedOnce(?string $published): string
    {
        $stream = $this->streamOf();

        if ($stream !== $published) {
            $this->updates->publish(new LiveEvent(DashboardTopics::SHELL, $stream));
        }

        return $stream;
    }

    // Compared as the rendered stream, because that is exactly what the page would change to.
    private function streamOf(): string
    {
        return $this->twig->render(self::TEMPLATE, ['headline' => $this->pages->board()->headline()]);
    }
}
