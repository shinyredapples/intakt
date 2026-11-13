<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;

final readonly class Issue
{
    public function __construct(
        public ?int $milestone,
        public ?DateTimeImmutable $closed,
        public bool $dropped,
    ) {
    }

    public function isOpen(): bool
    {
        return null === $this->closed;
    }

    public function isDone(): bool
    {
        return null !== $this->closed && !$this->dropped;
    }
}
