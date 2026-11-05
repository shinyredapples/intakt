<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\ReadOnlyException;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Budget\Contract\Holds;
use Intakt\Core\Budget\Contract\InstallWideFailures;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Budget\Domain\ItemFailureRecord;
use Intakt\Core\Platform\Contract\Redactor;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\Exception\LockStorageException;
use Throwable;

#[AsAlias(InstallWideFailures::class)]
final readonly class DoctrineInstallWideFailures implements InstallWideFailures
{
    // Long enough for a database or a lock store to come back after a restart, short enough that a cause already gone holds the install only briefly.
    private const int BACK_OFF_SECONDS = 600;

    private const int SAME_ERROR_WITHIN_SECONDS = 3600;

    // The database's connection or its write access, and the shared lock store: every item needs them, so no item can avoid their failure.
    /** @var list<class-string<Throwable>> */
    private const array NO_ITEM_CAN_AVOID = [
        ConnectionException::class,
        ReadOnlyException::class,
        LockAcquiringException::class,
        LockReleasingException::class,
        LockStorageException::class,
    ];

    public function __construct(
        private EntityManagerInterface $entities,
        private Holds $holds,
        private ClockInterface $clock,
        private Redactor $redactor,
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function itemFailed(string $item, Throwable $failure): void
    {
        if ($this->noItemCanAvoid($failure)) {
            $this->backOff('A failure no item can avoid starts the failure back-off.', $failure);

            return;
        }

        $error = $this->errorOf($failure);
        // Committed before the other items are read, so of two items failing at once at least one finds the other.
        $this->record(hash('sha256', $item), $error);

        if ($this->anotherItemFailedWith(hash('sha256', $item), $error)) {
            $this->backOff('The same error on two items within an hour starts the failure back-off.', $failure);
        }
    }

    #[Override]
    public function handOverFailed(Throwable $failure): void
    {
        $this->backOff('A hand-over that failed starts the failure back-off.', $failure);
    }

    // Along the whole chain, because a forge or a use case may wrap the database's or the lock store's failure in its own.
    private function noItemCanAvoid(Throwable $failure): bool
    {
        for ($cause = $failure; null !== $cause; $cause = $cause->getPrevious()) {
            if (array_any(self::NO_ITEM_CAN_AVOID, static fn (string $class): bool => $cause instanceof $class)) {
                return true;
            }
        }

        return false;
    }

    // Redacted as a log line is before it is hashed, so two failures that differ only in a credential count as one error.
    private function errorOf(Throwable $failure): string
    {
        return hash('sha256', $failure::class.': '.$this->redactor->redact(mb_scrub($failure->getMessage(), 'UTF-8')));
    }

    private function record(string $item, string $error): void
    {
        $now = $this->clock->now();
        $this->entities->createQueryBuilder()
            ->delete(ItemFailureRecord::class, 'failure')
            ->where('failure.failedAt <= :expired')
            ->setParameter('expired', $this->sameErrorSince(), 'utc_datetime_immutable')
            ->getQuery()
            ->execute();
        $record = new ItemFailureRecord($item, $error, $now);
        $this->entities->persist($record);
        $this->entities->flush();
        // Detached, because a worker keeps its entity manager and would otherwise hold every failure it ever recorded.
        $this->entities->detach($record);
    }

    private function anotherItemFailedWith(string $item, string $error): bool
    {
        $others = $this->entities->createQueryBuilder()
            ->select('COUNT(failure.position)')
            ->from(ItemFailureRecord::class, 'failure')
            ->where('failure.error = :error')
            ->andWhere('failure.item <> :item')
            ->andWhere('failure.failedAt > :since')
            ->setParameter('error', $error)
            ->setParameter('item', $item)
            ->setParameter('since', $this->sameErrorSince(), 'utc_datetime_immutable')
            ->getQuery()
            ->getSingleScalarResult();

        return 0 < (int) $others;
    }

    private function sameErrorSince(): DateTimeImmutable
    {
        return $this->clock->now()->modify(sprintf('-%d seconds', self::SAME_ERROR_WITHIN_SECONDS));
    }

    private function backOff(string $reason, Throwable $failure): void
    {
        $until = $this->clock->now()->modify(sprintf('+%d seconds', self::BACK_OFF_SECONDS));
        $this->holds->hold(SharedHold::FailureBackOff, $until);
        $this->logger->warning($reason, ['until' => $until->format(DATE_ATOM), 'exception' => $failure]);
    }
}
