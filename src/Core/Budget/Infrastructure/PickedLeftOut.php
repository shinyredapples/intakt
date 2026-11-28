<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use Intakt\Core\Budget\Contract\LeftOutCause;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class PickedLeftOut
{
    public function __construct(
        public string $account,
        public LeftOutCause $cause,
        // Kept as the stored text, because only an exact ATOM round trip proves a reset was not rolled over or read against the clock.
        public ?string $resetsAt,
    ) {
    }
}
