<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\RoundReviewer;
use NoDiscard;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

// Every decision about a reviewer is made on its locked row, because the same message can reach two workers: once more after a restart, or again while the first worker is still reviewing.
final readonly class ReviewerLeases
{
    private const string WORKER_STOPPED = 'the worker stopped twice before the reviewer had a verdict';
    private const string MESSAGE_FAILED = 'the review message failed for good';
    private const string CEILING_PASSED = 'the reviewer had no result within the review ceiling';
    private const int STORE_ATTEMPTS = 3;

    public function __construct(
        private EntityManagerInterface $entities,
        private ReviewerMoves $moves,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ReviewerNotFound
     * @throws ReviewerStillHeld
     */
    public function take(string $reviewerId): RoundReviewer
    {
        $now = $this->clock->now();

        return $this->inOneTransaction(function () use ($reviewerId, $now): RoundReviewer {
            $reviewer = $this->lockedRow(Uuid::fromString($reviewerId));
            $this->decide($reviewer, $now);

            return $reviewer;
        });
    }

    // Says whether the stored verdict answered a stop request, because the stop may be shown only once this transaction has committed.
    #[NoDiscard]
    public function recordVerdict(RoundReviewer $reviewer, Verdict $verdict, ?Provider $substitute): bool
    {
        return true === $this->record($reviewer, fn (RoundReviewer $stored): bool => $this->moves->receive($stored, $verdict, $substitute));
    }

    public function recordFailure(RoundReviewer $reviewer, string $failure): void
    {
        $this->record($reviewer, fn (RoundReviewer $stored) => $this->moves->fail($stored, $failure));
    }

    // Blocked with the reason, because no provider is left to review and a retry would only wait for one the owner named a way around.
    public function recordOutage(RoundReviewer $reviewer, string $reason): void
    {
        $this->record($reviewer, fn (RoundReviewer $stored) => $this->moves->block($stored, $reason));
    }

    // Called for a message that will not be tried again, so the reviewer is neither left waiting in the queue nor running for a worker that is gone.
    public function recordBlocked(string $reviewerId): Uuid
    {
        return $this->inOneTransaction(function () use ($reviewerId): Uuid {
            $reviewer = $this->lockedRow(Uuid::fromString($reviewerId));

            if (!$reviewer->isFinished()) {
                $this->moves->block($reviewer, self::MESSAGE_FAILED);
            }

            return $reviewer->round()->id();
        });
    }

    // Not counted as a restart, because only a stopped worker may use the one retry.
    public function handBack(RoundReviewer $reviewer): void
    {
        $this->inOneTransaction(function () use ($reviewer): void {
            $stored = $this->lockedRow($reviewer->id());

            if ($this->isStillHeldBy($reviewer, $stored)) {
                $this->moves->requeue($stored);
            }
        });
    }

    /**
     * @template T
     *
     * @param Closure(RoundReviewer): T $move
     *
     * @return T|null
     */
    private function record(RoundReviewer $reviewer, Closure $move): mixed
    {
        $attempts = 0;

        while (true) {
            try {
                return $this->recordOnce($reviewer, $move);
            } catch (ReviewEventNotRecorded $refused) {
                // A new transaction, because the result is already in hand and only a fresh snapshot can write after another reviewer committed.
                if (++$attempts < self::STORE_ATTEMPTS && $this->entities->isOpen()) {
                    continue;
                }

                // Left running rather than handed back, because a retry of a queued reviewer would call the vendor, and pay, again.
                throw ResultNotStored::after($attempts, $refused);
            }
        }
    }

    /**
     * @template T
     *
     * @param Closure(RoundReviewer): T $move
     *
     * @return T|null
     */
    private function recordOnce(RoundReviewer $reviewer, Closure $move): mixed
    {
        $now = $this->clock->now();

        return $this->inOneTransaction(function () use ($reviewer, $move, $now): mixed {
            $stored = $this->lockedRow($reviewer->id());

            // Left alone unless it is this worker's own attempt, because anything else was decided while the vendor call ran and stands.
            if (!$this->isStillHeldBy($reviewer, $stored)) {
                return null;
            }

            if (!$this->withinTheHold($stored, $now)) {
                $this->moves->fail($stored, self::CEILING_PASSED);

                return null;
            }

            return $move($stored);
        });
    }

    // The attempt too, because after the hold another worker may have started the reviewer again, and the row is then running for that worker.
    private function isStillHeldBy(RoundReviewer $taken, RoundReviewer $stored): bool
    {
        return ReviewerState::Running === $stored->state() && $stored->isSameAttemptAs($taken);
    }

    // Loaded fresh under the lock, because another worker may have changed the row since this worker loaded it; cleared first, since refreshing a loaded row would set its readonly columns again.
    private function lockedRow(Uuid $reviewerId): RoundReviewer
    {
        $this->entities->clear();
        $reviewer = $this->entities->find(RoundReviewer::class, $reviewerId, LockMode::PESSIMISTIC_WRITE);

        return $reviewer ?? throw ReviewerNotFound::for($reviewerId->toRfc4122());
    }

    private function decide(RoundReviewer $reviewer, DateTimeImmutable $now): void
    {
        if ($reviewer->isFinished()) {
            return;
        }

        if (ReviewerState::Queued === $reviewer->state()) {
            $this->moves->start($reviewer, $now);

            return;
        }

        $heldUntil = $reviewer->heldUntil();

        // Inside the hold the first worker is still reviewing, so the message comes back when the hold ends; after it, the worker counts as stopped.
        if ($heldUntil instanceof DateTimeImmutable && $this->withinTheHold($reviewer, $now)) {
            throw ReviewerStillHeld::until($heldUntil, $now);
        }

        // The worker counts as stopped, so its reviewer is started once more, because one stop may be a deploy; a second stop blocks it.
        $reviewer->hasRestarted() ? $this->moves->block($reviewer, self::WORKER_STOPPED) : $this->moves->restart($reviewer, $now);
    }

    // The hold is the review's ceiling too, because a result after it would land on a reviewer any redelivery may already treat as stopped.
    private function withinTheHold(RoundReviewer $reviewer, DateTimeImmutable $now): bool
    {
        $heldUntil = $reviewer->heldUntil();

        return $heldUntil instanceof DateTimeImmutable && $heldUntil > $now;
    }

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function inOneTransaction(Closure $work): mixed
    {
        // The connection's transaction rather than wrapInTransaction, because that closes the entity manager when the ledger refuses an event, and the hand-back and the next message still need it.
        try {
            return $this->entities->getConnection()->transactional(function () use ($work): mixed {
                $result = $work();
                $this->entities->flush();

                return $result;
            });
            // Boundary: clears the entity manager and rethrows because rolled-back entities and their database ids would collide with the next transaction.
        } catch (Throwable $failure) {
            $this->entities->clear();

            throw $failure;
        }
    }
}
