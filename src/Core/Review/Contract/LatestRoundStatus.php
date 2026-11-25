<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Plugin\Forge\Contract\RoundStatusLabel;

// Read from Intakt's own stored rounds, because the status label mirrors the round and nothing on the forge may decide it.
interface LatestRoundStatus
{
    // Null when the change has no round, so no status label belongs on it.
    public function of(string $project, string $change): ?RoundStatusLabel;
}
