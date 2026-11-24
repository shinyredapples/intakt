<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Watcher\Contract\WatcherLineRecorded;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Contracts\Service\ResetInterface;

// Published once the worker is done with the watch tick, not at each line, because the watcher writes its lines inside transactions: a push from inside one could show a state that is rolled back, and would hold the transaction open while the hub answers. Only the lines that decide a pull request's state move the cards, because a boot result changes nothing a card shows.
final class ProjectCardsWatcherUpdates implements ResetInterface
{
    private bool $moved = false;

    public function __construct(private readonly LiveProjectCards $cards)
    {
    }

    #[AsEventListener]
    public function noted(WatcherLineRecorded $line): void
    {
        $this->moved = $this->moved || in_array($line->kind, WatcherLines::ALL, true);
    }

    // Also after a failed tick, because the lines it committed before it failed stay in the ledger.
    #[AsEventListener(WorkerMessageHandledEvent::class)]
    #[AsEventListener(WorkerMessageFailedEvent::class)]
    public function published(): void
    {
        if (!$this->moved) {
            return;
        }

        $this->moved = false;
        $this->cards->publishLatest();
    }

    // Because the worker resets its services after each message, once the two events above were dispatched, a line noted in one message is never published by another.
    public function reset(): void
    {
        $this->moved = false;
    }
}
