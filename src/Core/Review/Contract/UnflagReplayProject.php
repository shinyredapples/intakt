<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Sent only after the project's replayed rows are gone, because a row left behind would then read as a live review.
final readonly class UnflagReplayProject
{
    public function __construct(public string $project)
    {
    }
}
