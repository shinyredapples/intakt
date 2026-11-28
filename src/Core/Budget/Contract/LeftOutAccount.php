<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The reset is the end of the window at the ceiling or of the hold, null when the reading names none, because the dashboard must not show a time it cannot back.
#[Exclude]
final readonly class LeftOutAccount
{
    public function __construct(
        public Account $account,
        public LeftOutCause $cause,
        public ?DateTimeImmutable $resetsAt,
    ) {
    }
}
