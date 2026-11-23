<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Platform\Contract\CommandMessage;

// Sent before the project's first round opens, because a round takes its mark when it opens and keeps it.
final readonly class FlagProjectAsReplay implements CommandMessage
{
    public function __construct(public string $project)
    {
    }
}
