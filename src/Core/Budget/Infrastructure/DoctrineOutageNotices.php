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
        // Not waiting, because a worker that holds the lock is noticing this account's outage right now, and a start must not wait for it.
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
        $notice = new OutageNoticeRecord($outage->account, $outage->lastReadingAt, $outage->seenAt);
        $this->entities->persist($notice);
        $this->entities->flush();
        $this->endIfReadSince($notice);
    }

    // A reading can land any time before this row is stored, and its own end of outages found no row yet, so the notice is ended by it here; looked up only once the row is stored, because a reading stored later finds the row itself. It looks again until the earliest reading stays the same across an update, because an earlier reading stored meanwhile skips a notice with no end yet (it cannot tell it from one that predates ends being kept), and a competing writer may have saved the same end first, which moves no row here; the loop stops once a pass changes nothing: it finds no reading after the outage, or the same earliest reading as the one it just ended with.
    private function endIfReadSince(OutageNoticeRecord $notice): void
    {
        $endedWith = null;
        while (true) {
            $earliest = $this->earliestReadingSince($notice);
            if (!$earliest instanceof DateTimeImmutable || $earliest->getTimestamp() === $endedWith?->getTimestamp()) {
                return;
            }

            $this->endWith($notice, $earliest);
            $endedWith = $earliest;
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

    // One statement past the entity, because a reading may end the same row at once, and the earlier reading wins.
    private function endWith(OutageNoticeRecord $notice, DateTimeImmutable $takenAt): void
    {
        $this->entities->createQueryBuilder()
            ->update(OutageNoticeRecord::class, 'notice')
            ->set('notice.endedAt', ':takenAt')
            ->where('notice.position = :position')
            ->andWhere('notice.endedAt IS NULL OR notice.endedAt > :takenAt')
            ->setParameter('takenAt', $takenAt, 'utc_datetime_immutable')
            ->setParameter('position', $notice->position)
            ->getQuery()
            ->execute();
    }

    private function wasNoticed(UsageReadingsStayedAway $outage): bool
    {
        return null !== $this->entities->getRepository(OutageNoticeRecord::class)->findOneBy([
            'provider' => $outage->account->provider,
            'account' => $outage->account->name,
            'lastReadingAt' => $outage->lastReadingAt,
        ]);
    }
}
