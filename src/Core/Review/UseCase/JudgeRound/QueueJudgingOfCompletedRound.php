<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Review\Domain\ReviewRound;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

// On the completed transition, which runs once per round inside the completing transaction; the Doctrine queue shares that transaction, so a round is never complete without its judging queued, and the call itself runs later in a worker, outside the round's lock; the failure stamp names what follows a judging that fails for good, so no listener of Intakt's own watches worker failures for it.
#[AsCompletedListener(workflow: 'review_round', transition: 'complete')]
final readonly class QueueJudgingOfCompletedRound
{
    public function __construct(private MessageBusInterface $commands)
    {
    }

    /**
     * @param CompletedEvent<object> $event
     */
    public function __invoke(CompletedEvent $event): void
    {
        $round = $event->getSubject();

        if ($round instanceof ReviewRound) {
            $this->commands->dispatch($this->judgingOf($round->id()->toRfc4122()));
        }
    }

    private function judgingOf(string $roundId): Envelope
    {
        return new Envelope(new JudgeRound($roundId), [RecordFailedJudging::onFailureOf($roundId)]);
    }
}
