<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
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
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

// Readings are appended, never updated in place, so the later takenAt is the latest and the higher position breaks a tie within one second; the per-account lock orders a reading against the outage notices of its account only.
#[AsAlias(MeasuredUse::class)]
final readonly class DoctrineMeasuredUse implements MeasuredUse
{
    public const string READINGS_LOCK = 'budget-readings-';

    public function __construct(
        private EntityManagerInterface $entities,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
        private LockFactory $locks,
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

        $ended = $this->storeWhileNoNoticeIsWritten($reading, $now);
        // Inside a caller's transaction nothing is committed yet, and its rollback would erase an end a listener already heard of, so the caller that owns the commit announces.
        if ($ended > 0 && !$this->entities->getConnection()->isTransactionActive()) {
            $this->events->dispatch(new OwnerNoticesChanged());
        }

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

    // Waiting and held across the commit, because on a read-committed engine a notice of this account stored meanwhile could miss this reading's end while its writer's look misses this uncommitted reading (#3293); inside a caller's transaction the commit is the caller's, so there is no commit of ours to hold the lock across.
    private function storeWhileNoNoticeIsWritten(WindowReading $reading, DateTimeImmutable $now): int
    {
        if ($this->entities->getConnection()->isTransactionActive()) {
            return $this->storeEndingOutagesAfter($reading, $now);
        }

        $lock = $this->locks->createLock(self::READINGS_LOCK.$reading->account->key());
        $lock->acquire(true);
        try {
            return $this->storeEndingOutagesAfter($reading, $now);
        } finally {
            $lock->release();
        }
    }

    private function storeEndingOutagesAfter(WindowReading $reading, DateTimeImmutable $now): int
    {
        // Read before the older readings are dropped, because one of them may be the reading that already ended an outage.
        $previous = $this->latestReadingBefore($reading->account, $now);

        return $this->storeEndingOutages(new WindowReadingRecord($reading, $now), $previous);
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
        } catch (Throwable $failure) {
            // Detached, because its row is rolled back and the database may give its position to the next reading, which the entity manager then refuses as a second object of one identity.
            $this->entities->detach($record);

            throw $failure;
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

    // The earlier reading wins, because a slow writer can store its reading after a later one; a reading between the outage and this one already ended it, so a notice left with no end from before ends were kept is not ended now.
    private function endOutagesBefore(WindowReadingRecord $record, ?DateTimeImmutable $previous): int
    {
        $query = $this->endingQuery($record, $previous);
        // A fresher reading of the account ended every outage before it when it was stored, and no notice of the account is written meanwhile, so a notice it left with no end is from before ends were kept, even with no earlier reading to guard by.
        if ($this->wasReadAfter($record)) {
            $query->andWhere('notice.endedAt IS NOT NULL');
        }

        $ended = $query->getQuery()->execute();

        return is_int($ended) ? $ended : 0;
    }

    private function endingQuery(WindowReadingRecord $record, ?DateTimeImmutable $previous): QueryBuilder
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

        return $query;
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
