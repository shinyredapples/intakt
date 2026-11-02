<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use DateTimeImmutable;

// Kept as one type for both, because a head is judged by all of them together.
final readonly class CiRun
{
    public function __construct(
        public CiRunOrigin $origin,
        public CiRunState $state,
        public DateTimeImmutable $startedAt,
    ) {
    }

    public function isNewerRunOf(self $other): bool
    {
        return $this->origin->isSameAs($other->origin) && $this->startedAt > $other->startedAt;
    }
}
