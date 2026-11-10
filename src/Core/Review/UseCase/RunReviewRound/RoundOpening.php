<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\Contract\RoundOpened;
use Intakt\Core\Review\Domain\ReviewRound;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class RoundOpening
{
    public function __construct(
        private ReviewRounds $rounds,
        private RoundLedger $ledger,
        private MessageBusInterface $commands,
        private EventDispatcherInterface $events,
    ) {
    }

    public function open(RunReviewRound $command): string
    {
        $round = $this->storeWithLedgerAndMessages($command);
        $this->events->dispatch(new RoundOpened($round->id()->toRfc4122(), $round->project()));

        return $round->id()->toRfc4122();
    }

    // One transaction with the Doctrine queue, because a round stored without its messages would never be resumed by any worker.
    private function storeWithLedgerAndMessages(RunReviewRound $command): ReviewRound
    {
        return $this->rounds->inOneTransaction(function () use ($command): ReviewRound {
            $round = $this->rounds->open($command);
            $this->ledger->reviewStarted($round, $command->panel->quorum, $command->round->source);
            $this->dispatchOneMessagePerReviewer($round);

            return $round;
        });
    }

    private function dispatchOneMessagePerReviewer(ReviewRound $round): void
    {
        foreach ($round->reviewers() as $reviewer) {
            $this->commands->dispatch(new ReviewWithReviewer($reviewer->id()->toRfc4122()));
        }
    }
}
