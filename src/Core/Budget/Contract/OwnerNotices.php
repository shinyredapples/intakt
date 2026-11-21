<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;

// The notices only the owner can act on that Budget keeps; until the owner notice records of plan T67 (#349) exist, these are the outage notices. An outage leaves the list when a newer reading of its account arrives, because nothing marks one handled yet.
interface OwnerNotices
{
    /**
     * @return list<OutageNotice>
     */
    public function stillRunningOldestFirst(): array;

    // The UTC day of the moment, because every moment Intakt stores is UTC; from the same notices as the list above, so a notice leaves one as it enters the other.
    /**
     * @return list<EndedOutageNotice>
     */
    public function endedOnTheDayOf(DateTimeImmutable $moment): array;
}
