<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// One window's measured use as the vendor reported it for one account; above 100 is kept, because a spent window is what the vendor said.
#[Exclude]
final readonly class WindowReading
{
    /**
     * @throws UsedPercentOutOfRange
     */
    public function __construct(
        public Account $account,
        public UsageWindow $window,
        public float $usedPercent,
        public ?DateTimeImmutable $resetsAt,
    ) {
        if (!is_finite($usedPercent) || $usedPercent < 0) {
            throw UsedPercentOutOfRange::of($usedPercent);
        }
    }
}
