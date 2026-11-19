<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\OwnerNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Budget\Domain\OutageNoticeRecord;
use Intakt\Core\Budget\Domain\WindowReadingRecord;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

// A notice ends when a reading of its account arrives, so it is read against the readings instead of being deleted.
#[AsAlias(OwnerNotices::class)]
final readonly class DoctrineOwnerNotices implements OwnerNotices
{
    public function __construct(
        private EntityManagerInterface $entities,
    ) {
    }

    #[Override]
    public function inForce(): array
    {
        $readingSince = $this->entities->createQueryBuilder()
            ->select('reading.position')
            ->from(WindowReadingRecord::class, 'reading')
            ->where('reading.provider = notice.provider')
            ->andWhere('reading.account = notice.account')
            ->andWhere('reading.takenAt >= notice.noticedAt');

        /** @var list<OutageNoticeRecord> $records */
        $records = $this->entities->createQueryBuilder()
            ->select('notice')
            ->from(OutageNoticeRecord::class, 'notice')
            ->where('NOT EXISTS (' . $readingSince->getDQL() . ')')
            ->orderBy('notice.position')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (OutageNoticeRecord $record): OwnerNotice => new OwnerNotice(
                new Account($record->provider, $record->account),
                $record->lastReadingAt,
                $record->noticedAt,
            ),
            $records,
        );
    }
}
