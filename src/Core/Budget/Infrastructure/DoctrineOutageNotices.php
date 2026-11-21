<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Budget\Contract\OwnerNoticesChanged;
use Intakt\Core\Budget\Domain\OutageNoticeRecord;
use Intakt\Core\Budget\Domain\UsageReadingsStayedAway;
use Intakt\Core\Budget\Domain\WindowReadingRecord;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

// A log line, because notices reach the owner through Plugin/Notifier only with plan T67; the row keeps it to once.
final readonly class DoctrineOutageNotices
{
    public function __construct(
        private EntityManagerInterface $entities,
        private LockFactory $locks,
        private LoggerInterface $logger,
        private EventDispatcherInterface $events,
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
        $this->entities->persist($this->endedIfReadSince(new OutageNoticeRecord($outage->account, $outage->lastReadingAt, $outage->seenAt)));
        $this->entities->flush();
        $this->events->dispatch(new OwnerNoticesChanged());
    }

    // A reading can land between the watch's look at the readings and this row, and its own end of outages found no row yet, so the notice is ended by it here.
    private function endedIfReadSince(OutageNoticeRecord $notice): OutageNoticeRecord
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

        foreach ($later as $reading) {
            $notice->endWith($reading->takenAt);
        }

        return $notice;
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
