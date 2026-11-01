<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class WindowUse
{
    public function __construct(
        public Usage $usage,
        public DateTimeImmutable $takenAt,
        public int $ageInSeconds,
    ) {
    }
}
