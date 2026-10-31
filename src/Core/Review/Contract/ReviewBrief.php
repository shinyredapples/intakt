<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// What a reviewer is told about the change: its task and, from round two on, the findings earlier rounds left open.
final readonly class ReviewBrief
{
    /**
     * @param list<Finding> $open
     */
    public function __construct(
        public string $task,
        public array $open,
    ) {
    }
}
