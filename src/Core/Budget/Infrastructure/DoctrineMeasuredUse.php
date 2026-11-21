<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\ReadingOutcome;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Budget\Domain\WindowReadingRecord;
use Override;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

// Readings are appended, never updated in place, so two workers recording one window at once need no lock: the later takenAt is the latest, and the higher position breaks a tie within one second.
#[AsAlias(MeasuredUse::class)]
final readonly class DoctrineMeasuredUse implements MeasuredUse
{
    public function __construct(
        private EntityManagerInterface $entities,
        private ClockInterface $clock,
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

        $record = new WindowReadingRecord($reading, $now);
        $this->entities->persist($record);
        $this->entities->flush();
        $this->forgetReadingsBefore($record);

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

    // Only the latest reading of a window is ever read, so the older ones are dropped to keep the table at about one row per window.
    // A reading stamped earlier but stored last deletes only what is older still, so the fresher one stays as evidence the account was read.
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
}
