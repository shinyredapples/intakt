<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;

// An outage is named by its account and the moment of the last reading before it, so a new outage after a new reading gets its own notice.
final readonly class UsageReadingsStayedAway
{
    public function __construct(
        public Account $account,
        public DateTimeImmutable $lastReadingAt,
        public DateTimeImmutable $seenAt,
    ) {
    }
}
