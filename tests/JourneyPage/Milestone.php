<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;

final readonly class Milestone
{
    public function __construct(
        public int $number,
        public string $title,
        public string $goal,
        public ?DateTimeImmutable $due,
    ) {
    }
}
