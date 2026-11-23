<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEndingLookup;
use Intakt\Core\Review\Contract\RoundEndings;

final readonly class FixedRoundEndings implements RoundEndings, RoundEndingLookup
{
    public function __construct(private ?RoundEnding $ending = null)
    {
    }

    public function lookup(): RoundEndingLookup
    {
        return $this;
    }

    public function ofUnjudged(string $roundId, int $reviewers, DateTimeImmutable $startedAt): ?RoundEnding
    {
        return $this->ending;
    }
}
