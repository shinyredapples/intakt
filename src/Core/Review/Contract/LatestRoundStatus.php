<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

interface LatestRoundStatus
{
    // Null for a change no round reviewed, because such a change was never under review and has no status to show.
    public function of(string $project, string $change): ?ReviewStatus;
}
