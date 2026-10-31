<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Domain\ReviewRound;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\UuidV7;

#[AsCommandHandler]
final readonly class RunReviewRoundHandler
{
    public function __construct(
        private EntityManagerInterface $entities,
        private RoundLedger $ledger,
        private MessageBusInterface $commands,
        private ClockInterface $clock,
    ) {
    }

    // Returns the id of the round because it names the rows and ledger events a caller reads back.
    public function __invoke(RunReviewRound $command): string
    {
        $round = $this->open($command);
        $this->ledger->reviewStarted($round);
        $this->dispatchOneMessagePerReviewer($round);

        return $round->id()->toRfc4122();
    }

    private function open(RunReviewRound $command): ReviewRound
    {
        // Built only to refuse a path that is no directory, because a round stored for one could never be reviewed.
        new Checkout($command->checkoutRoot);

        $round = new ReviewRound($this->newId(), $command->project, $command->checkoutRoot, $command->task);

        foreach ($command->panel->reviewers as $reviewer) {
            $round->addReviewer($this->newId(), $reviewer->role, $reviewer->model->tier);
        }

        $this->entities->persist($round);
        $this->entities->flush();

        return $round;
    }

    private function dispatchOneMessagePerReviewer(ReviewRound $round): void
    {
        foreach ($round->reviewers() as $reviewer) {
            $this->commands->dispatch(new ReviewWithReviewer($reviewer->id()->toRfc4122()));
        }
    }

    private function newId(): UuidV7
    {
        return new UuidV7(UuidV7::generate($this->clock->now()));
    }
}
