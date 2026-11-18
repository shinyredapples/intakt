<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Budget\Domain\OutageNoticeRecord;
use Intakt\Core\Budget\Domain\WindowReadingRecord;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(OwnerNotices::class)]
final readonly class DoctrineOwnerNotices implements OwnerNotices
{
    public function __construct(
        private EntityManagerInterface $entities,
    ) {
    }

    // A reading of the account taken after the notice's last one ends that outage, so the notice no longer waits for the owner; the position breaks a tie of one moment.
    #[Override]
    public function stillRunningOldestFirst(): array
    {
        $later = $this->entities->createQueryBuilder()
            ->select('reading.position')
            ->from(WindowReadingRecord::class, 'reading')
            ->where('reading.provider = notice.provider')
            ->andWhere('reading.account = notice.account')
            ->andWhere('reading.takenAt > notice.lastReadingAt');
        /** @var list<OutageNoticeRecord> $records */
        $records = $this->entities->createQueryBuilder()
            ->select('notice')
            ->from(OutageNoticeRecord::class, 'notice')
            ->where('NOT EXISTS ('.$later->getDQL().')')
            ->orderBy('notice.noticedAt', 'ASC')
            ->addOrderBy('notice.position', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (OutageNoticeRecord $record): OutageNotice => new OutageNotice(new Account($record->provider, $record->account), $record->lastReadingAt, $record->noticedAt),
            $records,
        );
    }
}
