<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// A command for the next round of a change: it queues the round and returns its id without waiting for the judge, so a web request can send it.
final readonly class StartReview
{
    public ChangeSource $source;

    public function __construct(
        public string $project,
        public string $task,
        public RequestedRound $round,
    ) {
        $this->source = $round->source ?? throw new RoundSourceMissing('A round started without waiting needs the repository and base its change is read from.');
    }
}
