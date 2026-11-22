<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

// A header list whose following text is kept: where the list starts and where that text resumes.
final readonly class KeptTail
{
    public function __construct(
        public int $listStart,
        public int $end,
    ) {
    }
}
