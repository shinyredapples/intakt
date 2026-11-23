<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Provider;

interface ReviewerRoles
{
    // Each name once, in the order a panel first lists it, because an owner reads which roles an account serves, not how often.
    /**
     * @return list<non-empty-string>
     */
    public function servedBy(Provider $provider): array;
}
