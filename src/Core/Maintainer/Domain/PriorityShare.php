<?php

declare(strict_types=1);

namespace Intakt\Core\Maintainer\Domain;

use Intakt\Plugin\WorkSource\Contract\TaskPriority;

final readonly class PriorityShare
{
    public function __construct(
        public TaskPriority $priority,
        public int $openTasks,
        public float $share,
    ) {
    }
}
