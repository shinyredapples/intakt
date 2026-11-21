<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Core\Review\Domain\KeptVerdict;
use Intakt\Core\Review\Domain\VerdictAlreadyKept;
use Symfony\Component\Clock\ClockInterface;

// The kept verdict of each finding key, written inside the caller's transaction; the key is the table's primary key, so the database decides which of two racing writers keeps its verdict.
final readonly class KeptVerdicts
{
    public function __construct(
        private EntityManagerInterface $entities,
        private ClockInterface $clock,
    ) {
    }

    public function keep(string $key, EscalationVerdict $verdict, string $round): void
    {
        try {
            // Inserted without a lookup first, because another writer can insert the key between the two; in a savepoint, because PostgreSQL refuses every further statement of a transaction in which one failed; past the ORM, because a refused flush closes the entity manager.
            $this->entities->getConnection()->transactional(fn (Connection $inner): int|string => $inner->insert(
                'kept_verdict',
                ['finding_key' => $key, 'outcome' => $verdict->outcome->value, 'reasons' => $verdict->reasons, 'round' => $round, 'recorded_at' => $this->clock->now()],
                ['recorded_at' => 'utc_datetime_immutable'],
            ));
        } catch (UniqueConstraintViolationException) {
            throw VerdictAlreadyKept::forKey($key);
        }
    }

    public function ofKey(string $key): ?KeptVerdict
    {
        return $this->entities->find(KeptVerdict::class, $key);
    }
}
