<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class JudgingFailed
{
    public function __construct(
        public string $round,
    ) {
    }
}
