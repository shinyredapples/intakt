<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Budget\Domain\OutageNoticeRecord;
use Intakt\Core\Budget\Domain\UsageReadingsStayedAway;
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
        $this->entities->persist(new OutageNoticeRecord($outage->account, $outage->lastReadingAt, $outage->seenAt));
        $this->entities->flush();
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
