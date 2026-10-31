<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\RoundReviewer;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

// Every decision about a reviewer is made on its locked row, because the same message can reach two workers: once more after a restart, or again while the first worker is still reviewing.
final readonly class ReviewerLeases
{
    private const string WORKER_STOPPED = 'the worker stopped before the reviewer had a verdict';
    private const string CEILING_PASSED = 'the reviewer had no result within the review ceiling';

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

    public function recordVerdict(RoundReviewer $reviewer, Verdict $verdict): void
    {
        $this->record($reviewer, fn (RoundReviewer $stored) => $this->moves->receive($stored, $verdict));
    }

    public function recordFailure(RoundReviewer $reviewer, string $failure): void
    {
        $this->record($reviewer, fn (RoundReviewer $stored) => $this->moves->fail($stored, $failure));
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

        $this->moves->fail($reviewer, self::WORKER_STOPPED);
    }

    /**
     * @param Closure(RoundReviewer): void $move
     */
    private function record(RoundReviewer $reviewer, Closure $move): void
    {
        $now = $this->clock->now();

        try {
            $this->inOneTransaction(function () use ($reviewer, $move, $now): void {
                $stored = $this->lockedRow($reviewer->id());

                // Still running means this worker's own hold, because the only other writer moves a reviewer whose hold ended to failed; anything else was decided while the vendor call ran and stands.
                if (ReviewerState::Running !== $stored->state()) {
                    return;
                }

                $this->withinTheHold($stored, $now) ? $move($stored) : $this->moves->fail($stored, self::CEILING_PASSED);
            });
        } catch (ReviewEventNotRecorded $refused) {
            $this->handBack($reviewer);

            throw $refused;
        }
    }

    // The hold is the review's ceiling too, because a result after it would land on a reviewer any redelivery may already treat as stopped.
    private function withinTheHold(RoundReviewer $reviewer, DateTimeImmutable $now): bool
    {
        $heldUntil = $reviewer->heldUntil();

        return $heldUntil instanceof DateTimeImmutable && $heldUntil > $now;
    }

    // Back to the queue, so the retry of the message runs the reviewer again instead of finding a hold nobody will release.
    private function handBack(RoundReviewer $reviewer): void
    {
        $this->inOneTransaction(function () use ($reviewer): void {
            $stored = $this->lockedRow($reviewer->id());

            if (ReviewerState::Running === $stored->state()) {
                $this->moves->requeue($stored);
            }
        });
    }

    // Loaded fresh under the lock, because another worker may have changed the row since this worker loaded it; cleared first, since refreshing a loaded row would set its readonly columns again.
    private function lockedRow(Uuid $reviewerId): RoundReviewer
    {
        $this->entities->clear();
        $reviewer = $this->entities->find(RoundReviewer::class, $reviewerId, LockMode::PESSIMISTIC_WRITE);

        return $reviewer ?? throw ReviewerNotFound::for($reviewerId->toRfc4122());
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
        } catch (Throwable $failure) {
            // Cleared because the rolled-back changes, and the ids the database gave them, are still in memory and would collide with the next transaction.
            $this->entities->clear();

            throw $failure;
        }
    }
}
