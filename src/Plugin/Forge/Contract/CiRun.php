<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use DateTimeImmutable;

// Kept as one type for both, because a head is judged by all of them together.
final readonly class CiRun
{
    /**
     * @param ?int $number the forge's count of runs of this origin, which breaks a tie of two runs created in the same second; null where the forge numbers none
     */
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
        if ($this->startedAt != $other->startedAt) {
            return $this->startedAt > $other->startedAt;
        }

        return null !== $this->number && null !== $other->number && $this->number > $other->number;
    }
}
