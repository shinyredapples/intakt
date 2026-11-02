<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Intakt\Core\Review\Contract\StartReviewAtHead;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

// Runs code while a start is in flight because that forces the order of two ticks.
final class RecordingCommandBus implements MessageBusInterface
{
    /** @var list<StartReviewAtHead> */
    public array $started = [];
    public ?Closure $whileStarting = null;

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        assert($message instanceof StartReviewAtHead);
        $this->started[] = $message;

        if ($this->whileStarting instanceof Closure) {
            ($this->whileStarting)($message);
        }

        return new Envelope($message, [new HandledStamp('round-'.count($this->started), 'handler')]);
    }
}
