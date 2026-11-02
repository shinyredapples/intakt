<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Intakt\Core\Review\Contract\StartReview;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

// Answers a round id like the review start does, and can run code while the start is in flight to force the order of two ticks.
final class RecordingCommandBus implements MessageBusInterface
{
    /** @var list<StartReview> */
    public array $started = [];
    public ?Closure $whileStarting = null;

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        assert($message instanceof StartReview);
        $this->started[] = $message;

        if (null !== $this->whileStarting) {
            ($this->whileStarting)();
        }

        return new Envelope($message, [new HandledStamp('round-'.count($this->started), 'handler')]);
    }
}
