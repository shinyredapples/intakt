<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// A round that ended without a judge's call, with the plain words the command and the tool print for it.
final readonly class RoundEnding
{
    public function __construct(
        public RoundEnds $end,
        public string $reason,
    ) {
    }
}
