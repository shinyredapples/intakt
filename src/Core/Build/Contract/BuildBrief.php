<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

final readonly class BuildBrief
{
    public function __construct(
        public string $task,
        public Decisions $decisions,
        public ?Rework $rework,
    ) {
    }
}
