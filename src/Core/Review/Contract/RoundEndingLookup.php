<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use DateTimeImmutable;

interface RoundEndingLookup
{
    // Null while the round may still be judged, because a caller that looks at a stored round has waited for nothing and so can only tell its end from the round and the clock.
    public function ofUnjudged(string $roundId, int $reviewers, DateTimeImmutable $startedAt): ?RoundEnding;
}
