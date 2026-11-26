<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\OwnerNoticesChanged;
use Intakt\Core\Budget\Contract\ReadingOutcome;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Budget\Domain\OutageNoticeRecord;
use Intakt\Core\Budget\Domain\WindowReadingRecord;
use Override;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

// Readings are appended, never updated in place, so the cleanup of two workers recording one window at once needs no lock of its own (the account lock, taken because of the notice writer, also serializes readings of one account outside a caller's transaction): the later takenAt is the latest, and the higher position breaks a tie within one second.
#[AsAlias(MeasuredUse::class)]
final readonly class DoctrineMeasuredUse implements MeasuredUse
{
    public function __construct(
        private EntityManagerInterface $entities,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
        private AccountReadingLock $accountLock,
    ) {
    }

    #[Override]
    public function record(WindowReading $reading): ReadingOutcome
    {
        $now = $this->clock->now();
        $refusal = $this->refusalOf($reading, $now);
        if ($refusal instanceof ReadingOutcome) {
            return $refusal;
        }

        // Inside a caller's transaction nothing is committed yet, and its rollback would erase an end a listener already heard of, so the caller that owns the commit announces; the same caller owns the commit, so the account lock cannot span it and is not taken here.
        if ($this->entities->getConnection()->isTransactionActive()) {
            $this->storeEndingOutages(new WindowReadingRecord($reading, $now), $this->latestReadingBefore($reading->account, $now));

            return ReadingOutcome::Recorded;
        }

        $this->recordAndCommit($reading, $now);

        return ReadingOutcome::Recorded;
    }

    #[Override]
    public function of(Account $account, UsageWindow $window): ?WindowUse
    {
        $latest = $this->entities->getRepository(WindowReadingRecord::class)->findOneBy(
            ['provider' => $account->provider, 'account' => $account->name, 'usageWindow' => $window],
            ['takenAt' => 'DESC', 'position' => 'DESC'],
        );
        if (null === $latest) {
            return null;
        }

        $age = $this->clock->now()->getTimestamp() - $latest->takenAt->getTimestamp();

        return new WindowUse($latest->usage(), $latest->takenAt, max(0, $age));
    }

    // A refused reading stores nothing, so the last good one stays.
    private function refusalOf(WindowReading $reading, DateTimeImmutable $now): ?ReadingOutcome
    {
        $resetsAt = $reading->usage->resetsAt;
        if (!$resetsAt instanceof DateTimeImmutable) {
            return null;
        }

        return $reading->window->refusalOfReset($resetsAt, $now);
    }

    private function latestReadingBefore(Account $account, DateTimeImmutable $moment): ?DateTimeImmutable
    {
        /** @var string|null $latest */
        $latest = $this->entities->createQueryBuilder()
            ->select('MAX(reading.takenAt)')
            ->from(WindowReadingRecord::class, 'reading')
            ->where('reading.provider = :provider')
            ->andWhere('reading.account = :account')
            ->andWhere('reading.takenAt < :moment')
            ->setParameter('provider', $account->provider)
            ->setParameter('account', $account->name)
            ->setParameter('moment', $moment, 'utc_datetime_immutable')
            ->getQuery()
            ->getSingleScalarResult();

        $converted = $this->entities->getConnection()->convertToPHPValue($latest, 'utc_datetime_immutable');

        return $converted instanceof DateTimeImmutable ? $converted : null;
    }

    // One transaction, so an end that fails to save rolls back the reading and the dropped readings too, and the next reading ends the outage instead; the connection's transaction rather than wrapInTransaction, because that closes the entity manager a worker goes on with.
    private function storeEndingOutages(WindowReadingRecord $record, ?DateTimeImmutable $previous): int
    {
        try {
            return $this->entities->getConnection()->transactional(function () use ($record, $previous): int {
                $this->entities->persist($record);
                $this->entities->flush();
                $this->forgetReadingsBefore($record);

                return $this->endOutagesBefore($record, $previous);
            });
            // Boundary: detaches the rolled-back reading on any failure and rethrows it, because whatever ended the transaction leaves the record without its row.
        } catch (Throwable $failure) {
            // Detached, because its row is rolled back and the database may give its position to the next reading, which the entity manager then refuses as a second object of one identity.
            $this->entities->detach($record);

            throw $failure;
        }
    }

    // Waiting for the lock, because a notice writer holds it only for its last look; held across the commit, because a writer that looked before the commit would otherwise miss this reading while this reading's update missed its notice. So a notice is stored either before this update, which ends it, or after the commit, when its writer sees this reading, and no end needs to run after the commit, where its failure would keep a later end.
    private function recordAndCommit(WindowReading $reading, DateTimeImmutable $now): void
    {
        $lock = $this->accountLock->of($reading->account);
        $lock->acquire(true);
        $ended = 0;
        try {
            // Read before the older readings are dropped, because one of them may be the reading that already ended an outage.
            $previous = $this->latestReadingBefore($reading->account, $now);
            $ended = $this->storeEndingOutages(new WindowReadingRecord($reading, $now), $previous);
        } finally {
            $lock->release();
            if ($ended > 0) {
                $this->events->dispatch(new OwnerNoticesChanged());
            }
        }
    }

    // Only the latest reading of a window is read, so older ones are dropped; one stamped earlier but stored last spares the fresher one, the evidence of a recovery.
    private function forgetReadingsBefore(WindowReadingRecord $record): void
    {
        $this->entities->createQueryBuilder()
            ->delete(WindowReadingRecord::class, 'reading')
            ->where('reading.provider = :provider')
            ->andWhere('reading.account = :account')
            ->andWhere('reading.usageWindow = :window')
            ->andWhere('reading.takenAt < :takenAt OR (reading.takenAt = :takenAt AND reading.position < :position)')
            ->setParameter('provider', $record->provider)
            ->setParameter('account', $record->account)
            ->setParameter('window', $record->usageWindow)
            ->setParameter('takenAt', $record->takenAt, 'utc_datetime_immutable')
            ->setParameter('position', $record->position)
            ->getQuery()
            ->execute();
    }

    // One statement, so two workers ending one outage at once need no lock; the earlier reading wins, because a slow writer can store its reading after a later one; a reading between the outage and this one already ended it, so a notice left with no end from before ends were kept is not ended now.
    private function endOutagesBefore(WindowReadingRecord $record, ?DateTimeImmutable $previous): int
    {
        $query = $this->entities->createQueryBuilder()
            ->update(OutageNoticeRecord::class, 'notice')
            ->set('notice.endedAt', ':takenAt')
            ->where('notice.provider = :provider')
            ->andWhere('notice.account = :account')
            ->andWhere('notice.lastReadingAt < :takenAt')
            ->andWhere('notice.endedAt IS NULL OR notice.endedAt > :takenAt')
            ->setParameter('takenAt', $record->takenAt, 'utc_datetime_immutable')
            ->setParameter('provider', $record->provider)
            ->setParameter('account', $record->account);
        if ($previous instanceof DateTimeImmutable) {
            $query->andWhere('notice.lastReadingAt >= :previous')->setParameter('previous', $previous, 'utc_datetime_immutable');
        }

        // A fresher reading of the account ended every outage before it when it was stored, so a notice it left with no end is from before ends were kept, even with no earlier reading to guard by.
        if ($this->wasReadAfter($record)) {
            $query->andWhere('notice.endedAt IS NOT NULL');
        }

        $ended = $query->getQuery()->execute();

        return is_int($ended) ? $ended : 0;
    }

    private function wasReadAfter(WindowReadingRecord $record): bool
    {
        $fresher = $this->entities->createQueryBuilder()
            ->select('COUNT(reading.position)')
            ->from(WindowReadingRecord::class, 'reading')
            ->where('reading.provider = :provider')
            ->andWhere('reading.account = :account')
            ->andWhere('reading.takenAt > :takenAt')
            ->setParameter('provider', $record->provider)
            ->setParameter('account', $record->account)
            ->setParameter('takenAt', $record->takenAt, 'utc_datetime_immutable')
            ->getQuery()
            ->getSingleScalarResult();

        return 0 < (int) $fresher;
    }
}
