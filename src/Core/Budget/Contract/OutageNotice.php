<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;

// One noticed outage of usage readings: the account, the last reading before it and the moment Intakt noticed.
final readonly class OutageNotice
{
    public function __construct(
        public Account $account,
        public DateTimeImmutable $lastReadingAt,
        public DateTimeImmutable $noticedAt,
    ) {
    }
}
