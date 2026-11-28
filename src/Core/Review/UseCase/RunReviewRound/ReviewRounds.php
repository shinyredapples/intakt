<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Closure;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Intakt\Core\Review\Contract\ChangeAddress;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\ReplayProject;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\ReadVerdicts\RoundNotFound;
use Symfony\Bridge\Doctrine\Types\UuidType;
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
        private RoundLedger $ledger,
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
        $this->markAsReplayWhenItsProjectIs($round);
        $this->addThePanelInIdOrder($round, $command->panel);

        $this->entities->persist($round);
        $this->entities->flush();

        return $round;
    }

    public function completeWhenEveryReviewerIsIn(Uuid $roundId): void
    {
        $this->withLockedRound($roundId, $this->completeAndRecordWhoReportedWhat(...));
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

    // Read as a column without clearing, because a reviewer's handler asks for it through the board while it still holds its round, and a clear would detach that round.
    public function replayMarkOf(string $roundId): ReplayMark
    {
        if (!Uuid::isValid($roundId)) {
            throw RoundNotFound::for($roundId);
        }

        /** @var list<array{replay: bool}> $rows */
        $rows = $this->entities->createQuery(sprintf('SELECT r.replay FROM %s r WHERE r.id = :id', ReviewRound::class))
            ->setParameter('id', Uuid::fromString($roundId), UuidType::NAME)
            ->getArrayResult();

        return match ($rows[0]['replay'] ?? null) {
            null => throw RoundNotFound::for($roundId),
            true => ReplayMark::Replay,
            false => ReplayMark::Live,
        };
    }

    // A forge status belongs to a commit, so only a later round that reviewed the same head speaks for it; a round of another head of the change leaves this head's status to this round. Compared as stored, because a round stores its address canonically (StoredFindings::openRound()), and an old spelling that migration Version20261122100000 left apart keeps its own numbering, which another sequence's numbers would not order.
    public function judgedAfter(ReviewRound $round): bool
    {
        /** @var list<ReviewRound> $later */
        $later = $this->entities->createQuery(sprintf('SELECT r FROM %s r WHERE r.subject.project = :project AND r.change.change = :change AND r.change.number > :number AND r.state = :judged', ReviewRound::class))
            ->setParameter('project', $round->project())
            ->setParameter('change', $round->change())
            ->setParameter('number', $round->number())
            ->setParameter('judged', RoundState::Judged->value)
            ->getResult();

        $head = $round->identity()->commits->head;

        return array_any($later, static fn (ReviewRound $other): bool => $other->identity()->commits->head->hash === $head->hash)
            || $this->canonicalRoundJudgedAt($round, $head->hash);
    }

    // In canonical form, because the watcher names the pull request as the forge lists it and a round stores it canonically (StoredFindings::openRound()). By round number as judgedAfter() orders them, not by id, because an id follows a clock that can go back and would pick a round the publication then drops as superseded.
    public function latestJudgedAt(string $project, string $change, string $head): ?ReviewRound
    {
        /** @var list<ReviewRound> $rounds */
        $rounds = $this->entities->createQuery(sprintf('SELECT r FROM %s r WHERE r.subject.project = :project AND r.change.change = :change AND r.state = :judged ORDER BY r.change.number DESC', ReviewRound::class))
            ->setParameter('project', $project)
            ->setParameter('change', ChangeAddress::canonical($change))
            ->setParameter('judged', RoundState::Judged->value)
            ->getResult();

        return array_find($rounds, static fn (ReviewRound $round): bool => $round->identity()->commits->head->hash === $head)
            ?? $this->judgedUnderAnOldSpelling($project, $change, $head);
    }

    // Newest first: the canonical sequence by number, then the rounds migration Version20261122100000 left under another spelling, which predate it and among which the highest number decides, as judgedUnderAnOldSpelling() orders them. Matched by the canonical form, because the caller may name the change as the forge lists it.
    /**
     * @return list<ReviewRound>
     */
    public function ofEverySpelling(string $project, string $change): array
    {
        $canonical = ChangeAddress::canonical($change);
        $number = ChangeAddress::pullRequestNumber($change);

        // Narrowed to the change's own spellings, because the read runs for every open pull request on each watcher pass and must not grow with the project's history. Any other address is stored as its canonical form; a pull request spelling holds /pull/<n> in its path, and the canonical filter below drops /pull/123 for /pull/12. A substring search instead of LIKE, because SQLite's LIKE stops at a NUL byte, which the canonical form trims from a stored spelling.
        $spelling = null === $number ? 'r.change.change = :change' : 'LOCATE(:change, r.change.change) > 0';
        // Refreshed, because a worker keeps its entity manager between messages, and a round it loaded earlier would hide the state and the publication other workers committed since.
        /** @var list<ReviewRound> $rounds */
        $rounds = $this->entities->createQuery(sprintf('SELECT r FROM %s r WHERE r.subject.project = :project AND %s ORDER BY r.change.number DESC', ReviewRound::class, $spelling))
            ->setParameter('project', $project)
            ->setParameter('change', null === $number ? $canonical : '/pull/'.$number)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
        $ofChange = array_values(array_filter($rounds, static fn (ReviewRound $round): bool => ChangeAddress::canonical($round->change()) === $canonical));

        return [
            ...array_filter($ofChange, static fn (ReviewRound $round): bool => $round->change() === $canonical),
            ...array_filter($ofChange, static fn (ReviewRound $round): bool => $round->change() !== $canonical),
        ];
    }

    // Compared as stored, as judgedAfter() compares them, so the rounds come as one numbered sequence.
    /**
     * @return list<ReviewRound>
     */
    public function ofChange(ReviewRound $round): array
    {
        /** @var list<ReviewRound> $rounds */
        $rounds = $this->entities->createQuery(sprintf('SELECT r FROM %s r WHERE r.subject.project = :project AND r.change.change = :change ORDER BY r.change.number ASC', ReviewRound::class))
            ->setParameter('project', $round->project())
            ->setParameter('change', $round->change())
            ->getResult();

        return $rounds;
    }

    // The round and every later round of the change on its head, because a re-review of a rejected escalation is a round no watcher started, and its approval must fall with the head's.
    /**
     * @return list<Uuid>
     */
    public function fromRoundOnItsHead(ReviewRound $round): array
    {
        $later = array_filter($this->ofChange($round), static fn (ReviewRound $other): bool => $other->number() > $round->number() && $other->sharesHeadWith($round));

        return [$round->id(), ...array_map(static fn (ReviewRound $other): Uuid => $other->id(), array_values($later))];
    }

    // Under the round's row lock, because a stale mark or a judging delivered again writes the same row meanwhile.
    public function recordPublication(ReviewRound $round): void
    {
        $this->withLockedRound($round->id(), fn (ReviewRound $locked) => $locked->recordPublication($this->clock->now()));
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

    // A round left under an old spelling predates every round of the canonical sequence, whose numbers do not compare with its own, so any judged canonical round of the same head supersedes it.
    private function canonicalRoundJudgedAt(ReviewRound $round, string $head): bool
    {
        $canonical = ChangeAddress::canonical($round->change());

        if ($canonical === $round->change()) {
            return false;
        }

        /** @var list<ReviewRound> $rounds */
        $rounds = $this->entities->createQuery(sprintf('SELECT r FROM %s r WHERE r.subject.project = :project AND r.change.change = :change AND r.state = :judged', ReviewRound::class))
            ->setParameter('project', $round->project())
            ->setParameter('change', $canonical)
            ->setParameter('judged', RoundState::Judged->value)
            ->getResult();

        return array_any($rounds, static fn (ReviewRound $other): bool => $other->identity()->commits->head->hash === $head);
    }

    // Only when the canonical sequence never judged this head, because a round that migration Version20261122100000 left under another spelling is the only verdict the head has; numbers of those sequences do not compare with each other, so the highest number decides among them and an id never does.
    private function judgedUnderAnOldSpelling(string $project, string $change, string $head): ?ReviewRound
    {
        $canonical = ChangeAddress::canonical($change);

        /** @var list<ReviewRound> $rounds */
        $rounds = $this->entities->createQuery(sprintf('SELECT r FROM %s r WHERE r.subject.project = :project AND r.change.change != :change AND r.state = :judged ORDER BY r.change.number DESC', ReviewRound::class))
            ->setParameter('project', $project)
            ->setParameter('change', $canonical)
            ->setParameter('judged', RoundState::Judged->value)
            ->getResult();

        // Matched by the canonical form of the stored address, as the migration grouped them, so an alias with a suffix is found and a local branch that differs only in case is not; the address is compared first so an unrelated round with a malformed historical identity is never read.
        return array_find($rounds, static fn (ReviewRound $round): bool => ChangeAddress::canonical($round->change()) === $canonical
            && $round->identity()->commits->head->hash === $head);
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
            // Boundary: clears the entity manager and rethrows because rolled-back entities and their database ids would collide with the next transaction.
        } catch (Throwable $failure) {
            $this->entities->clear();

            throw $failure;
        }
    }

    // In the transaction that records the verdicts, so the ledger holds which reviewer reported which finding exactly for the rounds whose findings were recorded.
    private function completeAndRecordWhoReportedWhat(ReviewRound $round): void
    {
        foreach ($this->findings->completeWhenEveryReviewerIsIn($round) as $reported) {
            $this->ledger->reviewerFindingsRecorded($reported);
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

    private function markAsReplayWhenItsProjectIs(ReviewRound $round): void
    {
        if ($this->entities->find(ReplayProject::class, $round->project()) instanceof ReplayProject) {
            $round->markAsReplay();
        }
    }

    // The ids are sorted before they are handed out in panel order, because a stored round lists its reviewers by id and an id taken at a clock time that went back would sort before the one taken just before it.
    private function addThePanelInIdOrder(ReviewRound $round, Panel $panel): void
    {
        $ids = array_map($this->newId(...), $panel->reviewers);
        usort($ids, static fn (UuidV7 $one, UuidV7 $other): int => $one->compare($other));

        foreach ($panel->reviewers as $position => $member) {
            $round->addReviewer($ids[$position], $member);
        }
    }
}
