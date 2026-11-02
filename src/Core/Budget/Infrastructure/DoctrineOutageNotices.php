<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Domain\OutageNoticeRecord;
use Intakt\Core\Budget\Domain\OutageNotices;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Lock\LockFactory;

// A log line, because notices reach the owner through Plugin/Notifier only with plan T67; the row keeps it to once.
#[AsAlias(OutageNotices::class)]
final readonly class DoctrineOutageNotices implements OutageNotices
{
    public function __construct(
        private EntityManagerInterface $entities,
        private ClockInterface $clock,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function noticeOnce(Account $account, DateTimeImmutable $lastReadingAt): void
    {
        // Not waiting, because a worker that holds the lock is noticing this account's outage right now, and a start must not wait for it.
        $lock = $this->locks->createLock('budget-outage-notice-'.$account->key());
        if (!$lock->acquire(false)) {
            return;
        }

        try {
            $this->noticeUnlessNoticed($account, $lastReadingAt);
        } finally {
            $lock->release();
        }
    }

    private function noticeUnlessNoticed(Account $account, DateTimeImmutable $lastReadingAt): void
    {
        if ($this->wasNoticed($account, $lastReadingAt)) {
            return;
        }

        $this->entities->persist(new OutageNoticeRecord($account, $lastReadingAt, $this->clock->now()));
        $this->entities->flush();
        $this->logger->warning('No usage of account {account} has been read since {since}, so only one call at a time runs on it.', [
            'account' => $account->key(),
            'since' => $lastReadingAt->format(DateTimeInterface::ATOM),
        ]);
    }

    private function wasNoticed(Account $account, DateTimeImmutable $lastReadingAt): bool
    {
        return null !== $this->entities->getRepository(OutageNoticeRecord::class)->findOneBy([
            'provider' => $account->provider,
            'account' => $account->name,
            'lastReadingAt' => $lastReadingAt,
        ]);
    }
}
