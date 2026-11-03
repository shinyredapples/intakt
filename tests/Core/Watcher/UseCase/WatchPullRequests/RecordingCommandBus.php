<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Intakt\Core\Review\Contract\DiscardQueuedRound;
use Intakt\Core\Review\Contract\QueuedRound;
use Intakt\Core\Review\Contract\StartReviewAtHead;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

// Runs code while a start is in flight because that forces the order of two ticks.
final class RecordingCommandBus implements MessageBusInterface
{
    /** @var list<StartReviewAtHead> */
    public array $started = [];
    /** @var list<QueuedRound> */
    public array $discarded = [];
    public ?Closure $whileStarting = null;
    public ?Throwable $discardFailure = null;

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($message instanceof DiscardQueuedRound) {
            $this->discarded[] = $message->round;

            if ($this->discardFailure instanceof Throwable) {
                throw $this->discardFailure;
            }

            return new Envelope($message);
        }

        assert($message instanceof StartReviewAtHead);
        $this->started[] = $message;

        if ($this->whileStarting instanceof Closure) {
            ($this->whileStarting)($message);
        }

        return new Envelope($message, [new HandledStamp(self::queued(count($this->started)), 'handler')]);
    }

    public static function queued(int $start): QueuedRound
    {
        return new QueuedRound('round-'.$start, 'snapshot-'.$start);
    }
}
