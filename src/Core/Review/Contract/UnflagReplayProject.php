<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Platform\Contract\CommandMessage;

// Sent only after the project's replayed rows are gone, because a row left behind would then read as a live review.
final readonly class UnflagReplayProject implements CommandMessage
{
    public function __construct(public string $project)
    {
    }
}
