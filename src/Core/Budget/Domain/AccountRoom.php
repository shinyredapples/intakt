<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\UsageWindow;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The reset is the one of the window at the ceiling when there is one, else the soonest of the windows with room; null when no reading names one.
#[Exclude]
final readonly class AccountRoom
{
    public function __construct(
        public ?UsageWindow $atCeiling,
        public ?DateTimeImmutable $resetsAt,
        public bool $trusted,
    ) {
    }
}
