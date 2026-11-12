<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class ToolCallCount
{
    public function __construct(
        public string $tool,
        public int $count,
    ) {
    }
}
