<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

interface OwnerNotices
{
    // Only outage notices so far, because the owner notice records of plan task T67 (#349) are not built yet.
    /**
     * @return list<OwnerNotice>
     */
    public function inForce(): array;
}
