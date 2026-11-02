<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\Domain\ReviewRound;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class RoundOpening
{
    public function __construct(
        private ReviewRounds $rounds,
        private RoundLedger $ledger,
        private MessageBusInterface $commands,
    ) {
    }

    // One transaction with the Doctrine queue, because a round stored without its messages would never be resumed by any worker.
    public function open(RunReviewRound $command): string
    {
        return $this->rounds->inOneTransaction(function () use ($command): string {
            $round = $this->rounds->open($command);
            $this->ledger->reviewStarted($round, $command->round->source);
            $this->dispatchOneMessagePerReviewer($round);

            return $round->id()->toRfc4122();
        });
    }

    private function dispatchOneMessagePerReviewer(ReviewRound $round): void
    {
        foreach ($round->reviewers() as $reviewer) {
            $this->commands->dispatch(new ReviewWithReviewer($reviewer->id()->toRfc4122()));
        }
    }
}
