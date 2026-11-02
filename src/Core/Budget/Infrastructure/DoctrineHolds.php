<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\Holds;
use Intakt\Core\Budget\Contract\ReadingOutcome;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Domain\AccountCooldownRecord;
use Intakt\Core\Budget\Domain\SharedHoldRecord;
use Override;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

// Holds are appended, never shortened, and one is in effect while any row of it still lies ahead, so two workers placing one at once need no lock.
#[AsAlias(Holds::class)]
final readonly class DoctrineHolds implements Holds
{
    public function __construct(
        private EntityManagerInterface $entities,
        private ClockInterface $clock,
    ) {
    }

    #[Override]
    public function coolDown(Account $account, DateTimeImmutable $resetsAt): ReadingOutcome
    {
        $now = $this->clock->now();
        $refusal = UsageWindow::longest()->refusalOfReset($resetsAt, $now);
        if ($refusal instanceof ReadingOutcome) {
            return $refusal;
        }

        $this->entities->persist(new AccountCooldownRecord($account, $resetsAt, $now));
        $this->entities->flush();

        return ReadingOutcome::Recorded;
    }

    #[Override]
    public function hold(SharedHold $hold, DateTimeImmutable $until): void
    {
        $this->entities->persist(new SharedHoldRecord($hold, $until, $this->clock->now()));
        $this->entities->flush();
    }

    #[Override]
    public function isCoolingDown(Account $account): bool
    {
        $ahead = $this->entities->createQueryBuilder()
            ->select('COUNT(cooldown.position)')
            ->from(AccountCooldownRecord::class, 'cooldown')
            ->where('cooldown.provider = :provider')
            ->andWhere('cooldown.account = :account')
            ->andWhere('cooldown.until > :now')
            ->setParameter('provider', $account->provider)
            ->setParameter('account', $account->name)
            ->setParameter('now', $this->clock->now(), 'utc_datetime_immutable')
            ->getQuery()
            ->getSingleScalarResult();

        return 0 < (int) $ahead;
    }

    #[Override]
    public function sharedInEffect(): array
    {
        $records = $this->entities->createQueryBuilder()
            ->select('hold')
            ->from(SharedHoldRecord::class, 'hold')
            ->where('hold.until > :now')
            ->setParameter('now', $this->clock->now(), 'utc_datetime_immutable')
            ->getQuery()
            ->getResult();
        $inEffect = array_map(static fn (SharedHoldRecord $record): SharedHold => $record->hold, $records);

        return array_values(array_filter(SharedHold::cases(), static fn (SharedHold $hold): bool => in_array($hold, $inEffect, true)));
    }
}
