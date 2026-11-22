<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\EndedOutageNotice;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Budget\Contract\OwnerNoticesOfTheDay;
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

    // A reading of the account taken after the notice's last one ends that outage, so the notice no longer waits for the owner; the saved end counts too, because a late write can drop the reading that ended it. The position breaks a tie of one moment.
    #[Override]
    public function stillRunningOldestFirst(): array
    {
        /** @var list<OutageNoticeRecord> $records */
        $records = $this->entities->createQueryBuilder()
            ->select('notice')
            ->from(OutageNoticeRecord::class, 'notice')
            ->where('notice.endedAt IS NULL')
            ->andWhere('NOT EXISTS ('.$this->laterReadingDql().')')
            ->orderBy('notice.noticedAt', 'ASC')
            ->addOrderBy('notice.position', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (OutageNoticeRecord $record): OutageNotice => new OutageNotice(new Account($record->provider, $record->account), $record->lastReadingAt, $record->noticedAt),
            $records,
        );
    }

    #[Override]
    public function endedOnTheDayOf(DateTimeImmutable $moment): array
    {
        $day = $this->utcDayOf($moment);
        // Columns, not entities, because a reading ends an outage with one statement past the entity manager, so a notice it already holds would keep no end.
        /** @var list<array{provider: Provider, account: string, endedAt: DateTimeImmutable}> $rows */
        $rows = $this->entities->createQueryBuilder()
            ->select('notice.provider AS provider', 'notice.account AS account', 'notice.endedAt AS endedAt')
            ->from(OutageNoticeRecord::class, 'notice')
            ->where('notice.endedAt >= :from')
            ->andWhere('notice.endedAt < :until')
            ->orderBy('notice.endedAt', 'DESC')
            ->addOrderBy('notice.position', 'DESC')
            ->setParameter('from', $day, 'utc_datetime_immutable')
            ->setParameter('until', $day->modify('+1 day'), 'utc_datetime_immutable')
            ->getQuery()
            ->getArrayResult();

        return array_map($this->endedNotice(...), $rows);
    }

    // One statement, so every row comes from one snapshot and a notice is either still waiting or ended, never both; columns for the reason given above.
    #[Override]
    public function waitingAndEndedOnTheDayOf(DateTimeImmutable $moment): OwnerNoticesOfTheDay
    {
        $day = $this->utcDayOf($moment);
        /** @var list<array{provider: Provider, account: string, lastReadingAt: DateTimeImmutable, noticedAt: DateTimeImmutable, endedAt: ?DateTimeImmutable, position: int}> $rows */
        $rows = $this->entities->createQueryBuilder()
            ->select('notice.provider AS provider', 'notice.account AS account', 'notice.lastReadingAt AS lastReadingAt', 'notice.noticedAt AS noticedAt', 'notice.endedAt AS endedAt', 'notice.position AS position')
            ->from(OutageNoticeRecord::class, 'notice')
            ->where('(notice.endedAt IS NULL AND NOT EXISTS ('.$this->laterReadingDql().')) OR (notice.endedAt >= :from AND notice.endedAt < :until)')
            ->orderBy('notice.noticedAt', 'ASC')
            ->addOrderBy('notice.position', 'ASC')
            ->setParameter('from', $day, 'utc_datetime_immutable')
            ->setParameter('until', $day->modify('+1 day'), 'utc_datetime_immutable')
            ->getQuery()
            ->getArrayResult();

        return new OwnerNoticesOfTheDay(
            $this->waitingOf($rows),
            $this->endedNewestFirstOf($rows),
        );
    }

    private function laterReadingDql(): string
    {
        return $this->entities->createQueryBuilder()
            ->select('reading.position')
            ->from(WindowReadingRecord::class, 'reading')
            ->where('reading.provider = notice.provider')
            ->andWhere('reading.account = notice.account')
            ->andWhere('reading.takenAt > notice.lastReadingAt')
            ->getDQL();
    }

    private function utcDayOf(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
    }

    /**
     * @param list<array{provider: Provider, account: string, lastReadingAt: DateTimeImmutable, noticedAt: DateTimeImmutable, endedAt: ?DateTimeImmutable, position: int}> $rows
     *
     * @return list<OutageNotice>
     */
    private function waitingOf(array $rows): array
    {
        $waiting = [];
        foreach ($rows as $row) {
            if (null === $row['endedAt']) {
                $waiting[] = new OutageNotice(new Account($row['provider'], $row['account']), $row['lastReadingAt'], $row['noticedAt']);
            }
        }

        return $waiting;
    }

    /**
     * @param list<array{provider: Provider, account: string, lastReadingAt: DateTimeImmutable, noticedAt: DateTimeImmutable, endedAt: ?DateTimeImmutable, position: int}> $rows
     *
     * @return list<EndedOutageNotice>
     */
    private function endedNewestFirstOf(array $rows): array
    {
        $ended = [];
        foreach ($rows as $row) {
            if (null !== $row['endedAt']) {
                $ended[] = ['provider' => $row['provider'], 'account' => $row['account'], 'endedAt' => $row['endedAt'], 'position' => $row['position']];
            }
        }
        usort($ended, static fn (array $first, array $second): int => [$second['endedAt'], $second['position']] <=> [$first['endedAt'], $first['position']]);

        return array_map(fn (array $row): EndedOutageNotice => $this->endedNotice(['provider' => $row['provider'], 'account' => $row['account'], 'endedAt' => $row['endedAt']]), $ended);
    }

    /**
     * @param array{provider: Provider, account: string, endedAt: DateTimeImmutable} $row
     */
    private function endedNotice(array $row): EndedOutageNotice
    {
        return new EndedOutageNotice(new Account($row['provider'], $row['account']), $row['endedAt']);
    }
}
