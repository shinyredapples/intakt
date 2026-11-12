<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;


final readonly class WorkPick
{
    private function __construct(
        public ?Task $task,
        public ?NothingPicked $nothing,
    ) {
    }

    public static function picked(Task $task): self
    {
        return new self($task, null);
    }

    public static function nothing(NothingPicked $why): self
    {
        return new self(null, $why);
    }
}
