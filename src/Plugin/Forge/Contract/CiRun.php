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
        public ?int $number = null,
    ) {
    }

    public function isNewerRunOf(self $other): bool
    {
        return $this->origin->isSameAs($other->origin) && $this->isLaterThan($other);
    }

    private function isLaterThan(self $other): bool
    {
        $order = $this->startedAt <=> $other->startedAt;
        if (0 !== $order) {
            return $order > 0;
        }

        // Compared by number too, because the forge stamps creation in whole seconds and two runs of one origin can share one.
        return null !== $this->number && null !== $other->number && $this->number > $other->number;
    }
}
