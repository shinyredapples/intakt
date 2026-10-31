<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Closure;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Domain\ReviewRound;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Workflow\WorkflowInterface;
use Throwable;

// The stored rounds: opened with their reviewers, and completed by whichever reviewer finishes last, because an earlier finisher finds the guard closed and a message delivered twice finds the round complete.
final readonly class ReviewRounds
{
    public function __construct(
        private EntityManagerInterface $entities,
        #[Target('review_round')]
        private WorkflowInterface $machine,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function inOneTransaction(Closure $work): mixed
    {
        // The connection's transaction rather than wrapInTransaction, because that closes the entity manager when the work fails, and a worker handles its next message with the same one.
        try {
            return $this->entities->getConnection()->transactional(function () use ($work): mixed {
                $result = $work();
                $this->entities->flush();

                return $result;
            });
        } catch (Throwable $failure) {
            // Cleared because the rolled-back changes, and the ids the database gave them, are still in memory and would collide with the next transaction.
            $this->entities->clear();

            throw $failure;
        }
    }

    public function open(RunReviewRound $command): ReviewRound
    {
        // Built only to refuse a path that is no directory, because a round stored for one could never be reviewed.
        new Checkout($command->subject->checkoutRoot);

        // Refused because verdicts recorded under another task or panel would read as current for a question the reviewers were never asked.
        if (!$command->identity->terms->describe($command->subject->task, $command->panel)) {
            throw IdentityNotOfRound::forTaskOrPanel();
        }

        $round = new ReviewRound($this->newId(), $command->subject, $command->identity);

        foreach ($command->panel->reviewers as $member) {
            $round->addReviewer($this->newId(), $member);
        }

        $this->entities->persist($round);
        $this->entities->flush();

        return $round;
    }

    public function completeWhenEveryReviewerIsIn(Uuid $roundId): void
    {
        // Cleared so the lock reads the reviewers other workers stored, not the ones this worker loaded earlier.
        $this->entities->clear();

        $this->inOneTransaction(function () use ($roundId): void {
            $round = $this->entities->find(ReviewRound::class, $roundId, LockMode::PESSIMISTIC_WRITE);

            if ($round instanceof ReviewRound && $this->machine->can($round, ReviewRound::COMPLETION)) {
                $this->machine->apply($round, ReviewRound::COMPLETION);
            }
        });
    }

    private function newId(): UuidV7
    {
        return new UuidV7(UuidV7::generate($this->clock->now()));
    }
}
