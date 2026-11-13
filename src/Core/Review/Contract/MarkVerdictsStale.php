<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Sent when the head a round reviewed is refused after the round started (it no longer boots), because no later identity tells Review that its verdicts stopped counting.
final readonly class MarkVerdictsStale
{
    public function __construct(public string $roundId)
    {
    }
}
