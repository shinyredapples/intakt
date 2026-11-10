<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\ReviewServerAccess;
use Symfony\Component\Uid\Uuid;

interface ReviewServerAccesses
{
    public function forRound(Uuid $round): ReviewServerAccess;
}
