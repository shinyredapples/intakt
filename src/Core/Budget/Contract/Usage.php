<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Above 100 is kept, because a spent window is what the vendor said.
#[Exclude]
final readonly class Usage
{
    /**
     * @throws UsedPercentOutOfRange
     */
    public function __construct(
        public float $usedPercent,
        public ?DateTimeImmutable $resetsAt,
    ) {
        if (!is_finite($usedPercent) || $usedPercent < 0) {
            throw UsedPercentOutOfRange::of($usedPercent);
        }
    }
}
