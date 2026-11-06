<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Budget\Contract\Holds;
use Intakt\Core\Budget\Contract\InstallWideFailures;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Budget\Domain\FullDisk;
use Intakt\Core\Budget\Domain\ItemFailureRecord;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

// The row is inserted before the others are read, so of two items failing at once at least one sees the other, and no lock is needed.
#[AsAlias(InstallWideFailures::class)]
final readonly class DoctrineInstallWideFailures implements InstallWideFailures
{
    private const string BACK_OFF = 'PT15M';
    private const string SAME_ERROR_WITHIN = 'PT1H';

    public function __construct(
        private EntityManagerInterface $entities,
        private ManagerRegistry $doctrine,
        private Holds $holds,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function databaseFailed(Throwable $failure): void
    {
        $this->backOff('the database failed', $failure);
    }

    #[Override]
    public function lockStoreFailed(Throwable $failure): void
    {
        $this->backOff('the lock store failed', $failure);
    }

    #[Override]
    public function itemFailed(string $item, Throwable $failure): void
    {
        if (FullDisk::causes($failure)) {
            $this->backOff('the disk is full', $failure);

            return;
        }

        $this->record($item, $this->errorKey($failure));

        if ($this->anotherItemFailedTheSame($item, $this->errorKey($failure))) {
            $this->backOff('two items failed with the same error within an hour', $failure);
        }
    }

    private function record(string $item, string $errorKey): void
    {
        $this->reopenEntityManager();
        $now = $this->clock->now();
        $this->entities->createQueryBuilder()
            ->delete(ItemFailureRecord::class, 'failure')
            ->where('failure.recordedAt < :cutoff')
            ->setParameter('cutoff', $this->cutoff($now), 'utc_datetime_immutable')
            ->getQuery()
            ->execute();

        $this->entities->persist(new ItemFailureRecord($item, $errorKey, $now));
        $this->entities->flush();
    }

    private function anotherItemFailedTheSame(string $item, string $errorKey): bool
    {
        $others = $this->entities->createQueryBuilder()
            ->select('COUNT(failure.position)')
            ->from(ItemFailureRecord::class, 'failure')
            ->where('failure.errorKey = :errorKey')
            ->andWhere('failure.item != :item')
            ->andWhere('failure.recordedAt >= :cutoff')
            ->setParameter('errorKey', $errorKey)
            ->setParameter('item', $item)
            ->setParameter('cutoff', $this->cutoff($this->clock->now()), 'utc_datetime_immutable')
            ->getQuery()
            ->getSingleScalarResult();

        return 0 < (int) $others;
    }

    private function cutoff(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->sub(new DateInterval(self::SAME_ERROR_WITHIN));
    }

    // Length-prefixed parts, so no class name and message can be moved against each other to make two errors look alike.
    private function errorKey(Throwable $failure): string
    {
        return hash('sha256', sprintf('%d:%s%d:%s', strlen($failure::class), $failure::class, strlen($failure->getMessage()), $failure->getMessage()));
    }

    private function backOff(string $cause, Throwable $failure): void
    {
        $this->reopenEntityManager();
        $this->holds->hold(SharedHold::FailureBackOff, $this->clock->now()->add(new DateInterval(self::BACK_OFF)));
        $this->logger->error('The install-wide failure back-off started because {cause}.', ['cause' => $cause, 'exception' => $failure]);
    }

    // The failure being reported is often a failed flush, which closes the entity manager for every holder and Doctrine refuses to reopen it, so without a reset the hold could not be written.
    private function reopenEntityManager(): void
    {
        if (!$this->entities->isOpen()) {
            $this->doctrine->resetManager();
        }
    }
}
