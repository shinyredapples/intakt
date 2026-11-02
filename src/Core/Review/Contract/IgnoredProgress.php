<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// The command uses it because its terminal shows only the result at the end.
final readonly class IgnoredProgress implements ReviewProgress
{
    public function reached(RoundProgress $progress): void
    {
    }
}
