<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

// Derived from the ledger on every call, because a halt stands until an owner-cleared event follows it and no table may say otherwise.
interface Halts
{
    // Oldest first, for every project, because the start and merge decisions do not know a project yet.
    /**
     * @return list<OwnerCard>
     */
    public function standing(): array;
}
