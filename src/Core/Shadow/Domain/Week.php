<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Domain;

use DateInterval;
use DateTimeImmutable;

final readonly class Week
{
    private function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $until,
    ) {
    }

    public static function endingAt(DateTimeImmutable $until): self
    {
        return new self($until->sub(new DateInterval('P7D')), $until);
    }

    // The end is left out, so a moment on the boundary between two weeks is counted in one of them only.
    public function holds(DateTimeImmutable $moment): bool
    {
        return $moment >= $this->from && $moment < $this->until;
    }
}
