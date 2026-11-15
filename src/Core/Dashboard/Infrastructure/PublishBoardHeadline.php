<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\RoundOpened;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Throwable;
use Twig\Environment;

// On a round's start and on every change of its progress, because those are the moments a lane appears on the board or leaves it.
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
        $this->progressed($opened->round);
    }

    public function progressed(string $round): void
    {
        try {
            $this->publishLatest();
        } catch (Throwable $failure) {
            // Swallowed after a log, the lock's own failures too, because the round is already committed and a live update must never change it.
            $this->logger->warning('The board headline was not published.', ['round' => $round, 'exception' => $failure]);
        }
    }

    // One worker at a time reads the board and publishes it, because two that read and published unordered could leave an older headline on every open page.
    private function publishLatest(): void
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

            if ($this->stream() === $published) {
                return;
            }
        }
    }

    private function publishedOnce(?string $published): string
    {
        $stream = $this->stream();

        if ($stream !== $published) {
            $this->updates->publish(new LiveEvent(DashboardTopics::SHELL, $stream));
        }

        return $stream;
    }

    // Compared as the rendered stream, because that is exactly what the page would change to.
    private function stream(): string
    {
        return $this->twig->render(self::TEMPLATE, ['lead' => $this->pages->board()->headline()]);
    }
}
