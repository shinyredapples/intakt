<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

final readonly class BuildBrief
{
    /**
     * @param list<string> $decisions
     */
    public function __construct(
        public string $task,
        public array $decisions,
        public ?Rework $rework,
        public TaskKind $kind = TaskKind::Task,
    ) {
    }
}
