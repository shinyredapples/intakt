<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

// Read apart from ReviewStarted, because the factor is the demo replay's own key and the Review module's start row knows nothing of it; a start row without the key (a live round, or a replay written before the factor was stored) reads with none.
final readonly class ReplayedStart
{
    public const string KEY = 'replay_factor';

    public function __construct(public ?float $replayFactor = null)
    {
    }

    // Only a factor the replay could have used, because a row is stored text and a page must not claim a pace no replay ran at.
    public function factor(): ?float
    {
        return null !== $this->replayFactor && is_finite($this->replayFactor) && $this->replayFactor > 0.0 ? $this->replayFactor : null;
    }
}
