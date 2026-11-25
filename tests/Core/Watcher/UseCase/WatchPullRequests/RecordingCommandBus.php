<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Intakt\Core\Review\Contract\DiscardQueuedRound;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\MarkVerdictsStale;
use Intakt\Core\Review\Contract\PublishHandedOverChange;
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
    /** @var list<string> */
    public array $markedStale = [];
    public ?Throwable $markFailure = null;

    /** @var list<PublishHandedOverChange> */
    public array $handedOver = [];

    #[Override]
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($message instanceof PublishHandedOverChange) {
            $this->handedOver[] = $message;

            return new Envelope($message);
        }

        if ($message instanceof MarkVerdictsStale) {
            $this->markedStale[] = $message->roundId;

            if ($this->markFailure instanceof Throwable) {
                throw $this->markFailure;
            }

            return new Envelope($message);
        }

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

        return new Envelope($message, [new HandledStamp(self::queued(count($this->started), $message->start->labels), 'handler')]);
    }

    public static function queued(int $start, Labels $labels = new Labels()): QueuedRound
    {
        return new QueuedRound('round-'.$start, 'snapshot-'.$start, $labels);
    }
}
