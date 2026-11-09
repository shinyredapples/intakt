<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use Symfony\Component\Uid\Uuid;

interface ReviewServerAccesses
{
    // Null when the round has no server to reach, so the caller keeps the rule for a reviewer that read nothing.
    public function forRound(?Uuid $round): ?ReviewServerAccess;
}
