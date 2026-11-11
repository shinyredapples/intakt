<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Review\Domain\ReviewRound;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

// On the judge transition, which runs once per round inside the judging transaction; the Doctrine queue shares that transaction, so a judged round is queued for publication exactly once, and the forge call runs later in a worker.
#[AsCompletedListener(workflow: 'review_round', transition: 'judge')]
final readonly class QueuePublicationOfJudgedRound
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
            $this->commands->dispatch(new PublishVerdict($round->id()->toRfc4122()));
        }
    }
}
