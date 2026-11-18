<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

// The notices only the owner can act on that Budget keeps; they stay listed because nothing marks one handled yet (plan T67).
interface OwnerNotices
{
    /**
     * @return list<OutageNotice>
     */
    public function oldestFirst(): array;
}
