<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Sent before the project's first round opens, because a round takes its mark when it opens and keeps it.
final readonly class FlagProjectAsReplay
{
    public function __construct(public string $project)
    {
    }
}
