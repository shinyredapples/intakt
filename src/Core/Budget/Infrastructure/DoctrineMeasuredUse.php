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

// Readings are appended, never updated in place, so two workers recording one window at once need no lock: the higher position is the latest.
#[AsAlias(MeasuredUse::class)]
final readonly class DoctrineMeasuredUse implements MeasuredUse
{
    public function __construct(
        private EntityManagerInterface $entities,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
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

        // Read before the older readings are dropped, because one of them may be the reading that already ended an outage.
        $previous = $this->latestReadingBefore($reading->account, $now);
        $record = new WindowReadingRecord($reading, $now);
        $this->entities->persist($record);
        $this->entities->flush();
        $this->forgetReadingsBefore($record);
        $this->endOutagesBefore($record, $previous);

        return ReadingOutcome::Recorded;
    }

    #[Override]
    public function of(Account $account, UsageWindow $window): ?WindowUse
    {
        $latest = $this->entities->getRepository(WindowReadingRecord::class)->findOneBy(
            ['provider' => $account->provider, 'account' => $account->name, 'usageWindow' => $window],
            ['position' => 'DESC'],
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

    // Only the latest reading of a window is ever read, so the older ones are dropped to keep the table at about one row per window.
    private function forgetReadingsBefore(WindowReadingRecord $record): void
    {
        $this->entities->createQueryBuilder()
            ->delete(WindowReadingRecord::class, 'reading')
            ->where('reading.provider = :provider')
            ->andWhere('reading.account = :account')
            ->andWhere('reading.usageWindow = :window')
            ->andWhere('reading.position < :position')
            ->setParameter('provider', $record->provider)
            ->setParameter('account', $record->account)
            ->setParameter('window', $record->usageWindow)
            ->setParameter('position', $record->position)
            ->getQuery()
            ->execute();
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

    // One statement, so two workers ending one outage at once need no lock; the earlier reading wins, because a slow writer can store its reading after a later one; a reading between the outage and this one already ended it, so a notice left with no end from before ends were kept is not ended now.
    private function endOutagesBefore(WindowReadingRecord $record, ?DateTimeImmutable $previous): void
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

        $ended = $query->getQuery()->execute();

        if (0 < $ended) {
            $this->events->dispatch(new OwnerNoticesChanged());
        }
    }
}
