<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\LeftOutCause;

final readonly class PickedLeftOut
{
    public function __construct(
        public string $account,
        public LeftOutCause $cause,
        public ?DateTimeImmutable $resetsAt,
    ) {
    }
}
