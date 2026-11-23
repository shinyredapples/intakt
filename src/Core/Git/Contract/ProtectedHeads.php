<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use NoDiscard;

interface ProtectedHeads
{
    // A refused project name, head or list answers as unreadable, because a caller deciding whether a head needs the owner must never get an unchecked head through as untouched.
    #[NoDiscard]
    public function of(string $project, string $head): ProtectedChanges;
}
