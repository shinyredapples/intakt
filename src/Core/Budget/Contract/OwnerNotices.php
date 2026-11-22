<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;

// The notices only the owner can act on that Budget keeps; until the owner notice records of plan T67 (#349) exist, these are the outage notices. An outage leaves the list when a newer reading of its account arrives, because nothing marks one handled yet, and Budget keeps the moment of that reading as the notice's end.
interface OwnerNotices
{
    /**
     * @return list<OutageNotice>
     */
    public function stillRunningOldestFirst(): array;

    // The UTC day of the moment, because every moment Intakt stores is UTC; from the same notices as the list above, so a notice leaves one as it enters the other, and a saved end keeps it out of the list above even once the reading that ended it is gone. Only a notice that ended before ends were kept leaves the list above without entering this one.
    /**
     * @return list<EndedOutageNotice>
     */
    public function endedOnTheDayOf(DateTimeImmutable $moment): array;

    // Both lists above from one statement, because a recovery committed between two reads would list one outage as both waiting and ended.
    public function waitingAndEndedOnTheDayOf(DateTimeImmutable $moment): OwnerNoticesOfTheDay;
}
