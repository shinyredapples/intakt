<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEndings;

final readonly class FixedRoundEndings implements RoundEndings
{
    public function __construct(private ?RoundEnding $ending = null)
    {
    }

    public function ofUnjudged(string $roundId, int $reviewers, DateTimeImmutable $startedAt): ?RoundEnding
    {
        return $this->ending;
    }
}
