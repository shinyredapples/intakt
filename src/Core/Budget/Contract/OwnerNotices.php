<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

// The notices only the owner can act on that Budget keeps; an outage leaves the list when a newer reading of its account arrives, because nothing marks one handled yet (plan T67).
interface OwnerNotices
{
    /**
     * @return list<OutageNotice>
     */
    public function stillRunningOldestFirst(): array;
}
