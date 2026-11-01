<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Closure;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\ReadVerdicts\RoundNotFound;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Throwable;

// The stored rounds: opened with their reviewers, and completed by whichever reviewer finishes last, because an earlier finisher finds the guard closed and a message delivered twice finds the round complete.
final readonly class ReviewRounds
{
    // Three, because each retry follows a commit of another round of the change, and more rounds than that rarely start at once.
    private const int ATTEMPTS = 3;

    public function __construct(
        private EntityManagerInterface $entities,
        private ClockInterface $clock,
        private StoredFindings $findings,
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
        return $this->attempt($work, self::ATTEMPTS);
    }

    public function open(RunReviewRound $command): ReviewRound
    {
        // Built only to refuse a path that is no directory, because a round stored for one could never be reviewed.
        $subject = $command->target->subject;
        new Checkout($subject->checkoutRoot);

        // Refused because verdicts recorded under another task or panel would read as current for a question the reviewers were never asked.
        if (!$command->target->identity->terms->describes($subject->task, $command->panel)) {
            throw IdentityNotOfRound::forTaskOrPanel();
        }

        $opened = $this->findings->openRound($subject->project, $command->round->change);
        $this->refuseAnotherNumber($command, $opened);
        $round = new ReviewRound($this->newId(), $command->target, $opened);

        foreach ($command->panel->reviewers as $member) {
            $round->addReviewer($this->newId(), $member);
        }

        $this->entities->persist($round);
        $this->entities->flush();

        return $round;
    }

    public function completeWhenEveryReviewerIsIn(Uuid $roundId): void
    {
        $this->withLockedRound($roundId, $this->findings->completeWhenEveryReviewerIsIn(...));
    }

    public function stored(string $roundId): ReviewRound
    {
        if (!Uuid::isValid($roundId)) {
            throw RoundNotFound::for($roundId);
        }

        // Cleared because a worker keeps its entity manager between messages, and the verdicts other workers stored since are the truth.
        $this->entities->clear();

        return $this->entities->find(ReviewRound::class, Uuid::fromString($roundId)) ?? throw RoundNotFound::for($roundId);
    }

    /**
     * @param Closure(ReviewRound): mixed $work
     */
    public function withLockedRound(Uuid $roundId, Closure $work): void
    {
        // Cleared so the lock reads the reviewers other workers stored, not the ones this worker loaded earlier.
        $this->entities->clear();

        $this->inOneTransaction(function () use ($roundId, $work): void {
            $round = $this->entities->find(ReviewRound::class, $roundId, LockMode::PESSIMISTIC_WRITE);

            if ($round instanceof ReviewRound) {
                $work($round);
            }
        });
    }

    /**
     * @return list<Finding>
     */
    public function openFindingsOf(ReviewRound $round): array
    {
        return $this->findings->openFor($round);
    }

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function attempt(Closure $work, int $attemptsLeft): mixed
    {
        try {
            return $this->once($work);
        } catch (RetryableException $failure) {
            // Not retried inside a caller's transaction, because only the outermost one can start over from a fresh snapshot.
            if (1 === $attemptsLeft || $this->entities->getConnection()->isTransactionActive()) {
                throw $failure;
            }

            // Rethrown because a failed flush closes the entity manager, and a retry on it would fail with EntityManagerClosed and hide this failure.
            if (!$this->entities->isOpen()) {
                throw $failure;
            }

            // The whole transaction again, because on SQLite a transaction that read before another writer committed cannot write at all, and only a new one sees that commit.
            return $this->attempt($work, $attemptsLeft - 1);
        }
    }

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function once(Closure $work): mixed
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

    // Thrown inside the transaction, so the number taken is rolled back with it.
    private function refuseAnotherNumber(RunReviewRound $command, ChangeRound $opened): void
    {
        $expected = $command->round->number;

        if (null !== $expected && $expected !== $opened->number) {
            throw RoundNotNext::for($expected, $opened->number);
        }
    }

    private function newId(): UuidV7
    {
        return new UuidV7(UuidV7::generate($this->clock->now()));
    }
}
