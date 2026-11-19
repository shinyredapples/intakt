<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The last reading is kept with the account because it tells the owner how long the account has been unread.
#[Exclude]
final readonly class OutageNotice
{
    public function __construct(
        public Account $account,
        public DateTimeImmutable $lastReadingAt,
        public DateTimeImmutable $noticedAt,
    ) {
    }
}
