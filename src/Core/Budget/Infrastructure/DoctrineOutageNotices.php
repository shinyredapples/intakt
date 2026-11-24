<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Budget\Domain\OutageNoticeRecord;
use Intakt\Core\Budget\Domain\UsageReadingsStayedAway;
use Intakt\Core\Budget\Domain\WindowReadingRecord;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Lock\LockFactory;

// A log line, because notices reach the owner through Plugin/Notifier only with plan T67; the row keeps it to once.
final readonly class DoctrineOutageNotices
{
    public function __construct(
        private EntityManagerInterface $entities,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function noticeOnce(UsageReadingsStayedAway $outage): void
    {
        // Not waiting, because a worker that holds the lock is noticing this account's outage right now, and a start must not wait for it; the wait for the readings lock below is bounded by one short reading commit.
        $lock = $this->locks->createLock('budget-outage-notice-'.$outage->account->key());
        if (!$lock->acquire(false)) {
            return;
        }

        try {
            $this->noticeUnlessNoticed($outage);
        } finally {
            $lock->release();
        }
    }

    // The warning goes before the row, because a warning that failed must be tried again, and a crash between the two costs at most a repeated warning.
    private function noticeUnlessNoticed(UsageReadingsStayedAway $outage): void
    {
        if ($this->wasNoticed($outage)) {
            return;
        }

        $this->logger->warning('No usage of account {account} has been read since {since}, so only one call at a time runs on it.', [
            'account' => $outage->account->key(),
            'since' => $outage->lastReadingAt->format(DateTimeInterface::ATOM),
        ]);
        $this->storeWhileNoReadingIsRecorded($outage);
    }

    private function wasNoticed(UsageReadingsStayedAway $outage): bool
    {
        return null !== $this->entities->getRepository(OutageNoticeRecord::class)->findOneBy([
            'provider' => $outage->account->provider,
            'account' => $outage->account->name,
            'lastReadingAt' => $outage->lastReadingAt,
        ]);
    }

    // Waiting, because recording a reading is brief, and while this row is stored and ended no reading of the account can run its end before the row exists or commit after this look (#3293); inside a caller's transaction the caller owns both sides on one connection, and its worker may hold the lock itself, so there is nothing to wait for.
    private function storeWhileNoReadingIsRecorded(UsageReadingsStayedAway $outage): void
    {
        if ($this->entities->getConnection()->isTransactionActive()) {
            $this->store($outage);

            return;
        }

        $lock = $this->locks->createLock(DoctrineMeasuredUse::READINGS_LOCK.$outage->account->key());
        $lock->acquire(true);
        try {
            $this->store($outage);
        } finally {
            $lock->release();
        }
    }

    private function store(UsageReadingsStayedAway $outage): void
    {
        $notice = new OutageNoticeRecord($outage->account, $outage->lastReadingAt, $outage->seenAt);
        $this->entities->persist($notice);
        $this->entities->flush();
        $this->endIfReadSince($notice);
    }

    // A reading stored before this row found no row to end, so the earliest of them ends it here; one stored later finds the row itself.
    private function endIfReadSince(OutageNoticeRecord $notice): void
    {
        $earliest = $this->earliestReadingSince($notice);
        if ($earliest instanceof DateTimeImmutable) {
            $this->endWith($notice, $earliest);
        }
    }

    private function earliestReadingSince(OutageNoticeRecord $notice): ?DateTimeImmutable
    {
        /** @var list<WindowReadingRecord> $later */
        $later = $this->entities->createQueryBuilder()
            ->select('reading')
            ->from(WindowReadingRecord::class, 'reading')
            ->where('reading.provider = :provider')
            ->andWhere('reading.account = :account')
            ->andWhere('reading.takenAt > :lastReadingAt')
            ->orderBy('reading.takenAt', 'ASC')
            ->setParameter('provider', $notice->provider)
            ->setParameter('account', $notice->account)
            ->setParameter('lastReadingAt', $notice->lastReadingAt, 'utc_datetime_immutable')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return [] === $later ? null : $later[0]->takenAt;
    }

    // One statement past the entity, because the row is already flushed and its end alone changes.
    private function endWith(OutageNoticeRecord $notice, DateTimeImmutable $takenAt): void
    {
        $this->entities->createQueryBuilder()
            ->update(OutageNoticeRecord::class, 'notice')
            ->set('notice.endedAt', ':takenAt')
            ->where('notice.position = :position')
            ->setParameter('takenAt', $takenAt, 'utc_datetime_immutable')
            ->setParameter('position', $notice->position)
            ->getQuery()
            ->execute();
    }
}
