<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Domain\ReviewRound;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommandHandler]
final readonly class RunReviewRoundHandler
{
    public function __construct(
        private ReviewRounds $rounds,
        private RoundLedger $ledger,
        private MessageBusInterface $commands,
    ) {
    }

    // Returns the id of the round because it names the rows and ledger events a caller reads back.
    public function __invoke(RunReviewRound $command): string
    {
        // One transaction with the Doctrine queue, because a round stored without its messages would never be resumed by any worker.
        return $this->rounds->inOneTransaction(function () use ($command): string {
            $round = $this->rounds->open($command);
            $this->ledger->reviewStarted($round);
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
