<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\IndexResolvedFindings;

use Intakt\Core\Review\Domain\ReviewRound;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

// On the completed transition, which runs once per round inside the completing transaction; the Doctrine queue shares that transaction, so a round is never complete without its indexing queued, and the store write runs later in a worker, outside any transaction.
#[AsCompletedListener(workflow: 'review_round', transition: 'complete')]
final readonly class QueueIndexingOfCompletedRound
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
            $this->commands->dispatch(new IndexResolvedFindings($round->id()->toRfc4122()));
        }
    }
}
