<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

// The notices only the owner can act on that Budget keeps; until the owner notice records of plan T67 (#349) exist, these are the outage notices. An outage leaves the list when a newer reading of its account arrives, because nothing marks one handled yet.
interface OwnerNotices
{
    /**
     * @return list<OutageNotice>
     */
    public function stillRunningOldestFirst(): array;
}
