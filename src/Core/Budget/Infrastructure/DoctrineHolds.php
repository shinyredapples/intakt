<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Doctrine\Common\Collections\Criteria;
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
        return $this->coolingDownUntil($account) instanceof DateTimeImmutable;
    }

    #[Override]
    public function coolingDownUntil(Account $account): ?DateTimeImmutable
    {
        $latest = $this->entities->createQueryBuilder()
            ->select('cooldown')
            ->from(AccountCooldownRecord::class, 'cooldown')
            ->where('cooldown.provider = :provider')
            ->andWhere('cooldown.account = :account')
            ->andWhere('cooldown.until > :now')
            ->orderBy('cooldown.until', 'DESC')
            ->setMaxResults(1)
            ->setParameter('provider', $account->provider)
            ->setParameter('account', $account->name)
            ->setParameter('now', $this->clock->now(), 'utc_datetime_immutable')
            ->getQuery()
            ->getOneOrNullResult();

        return $latest instanceof AccountCooldownRecord ? $latest->until : null;
    }

    #[Override]
    public function sharedInEffect(): array
    {
        $ahead = Criteria::create()->where(Criteria::expr()->gt('until', $this->clock->now()));
        $inEffect = $this->entities->getRepository(SharedHoldRecord::class)->matching($ahead)
            ->map(static fn (SharedHoldRecord $record): SharedHold => $record->hold)
            ->toArray();

        return array_values(array_filter(SharedHold::cases(), static fn (SharedHold $hold): bool => in_array($hold, $inEffect, true)));
    }
}
