<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The moment of the reading that ended the outage, because the owner reads when it stopped waiting, not when it began.
#[Exclude]
final readonly class EndedOutageNotice
{
    public function __construct(
        public Account $account,
        public DateTimeImmutable $endedAt,
    ) {
    }
}
