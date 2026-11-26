<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;

final readonly class Task
{
    // The open pull request is read from the forge, by the task's branch and by closing keyword, in the tick that queues the task, never remembered, so a task that has one is a rework of it, never a fresh build.
    public function __construct(
        public int $number,
        public string $title,
        public ?int $reworkOf,
    ) {
    }
}
