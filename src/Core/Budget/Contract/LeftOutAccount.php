<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The reset is null when the reading names none or the cause has no known end, because the dashboard must not show a time it cannot back.
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
