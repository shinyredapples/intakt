<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

final readonly class KeptTail
{
    public function __construct(
        public int $listStart,
        public int $end,
    ) {
    }
}
