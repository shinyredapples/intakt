<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Core\Review\Domain\KeptVerdict;
use Intakt\Core\Review\Domain\VerdictAlreadyKept;
use Symfony\Component\Clock\ClockInterface;

// The kept verdict of each finding key, written inside the caller's transaction; the key is the table's primary key, so a second verdict for one key is refused even when two writers race.
final readonly class KeptVerdicts
{
    public function __construct(
        private EntityManagerInterface $entities,
        private ClockInterface $clock,
    ) {
    }

    public function keep(string $key, EscalationVerdict $verdict, string $round): void
    {
        if ($this->ofKey($key) instanceof KeptVerdict) {
            throw VerdictAlreadyKept::forKey($key);
        }

        $this->entities->persist(new KeptVerdict($key, $verdict->outcome, $verdict->reasons, $round, $this->clock->now()->setTimezone(new DateTimeZone('UTC'))));
        $this->entities->flush();
    }

    public function ofKey(string $key): ?KeptVerdict
    {
        return $this->entities->find(KeptVerdict::class, $key);
    }
}
