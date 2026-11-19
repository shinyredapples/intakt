<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// A task the build has recorded a step for, read from its latest step, because a task has no card until the build lane lands but still ends the first day and, while in flight, keeps its project awake (#2732); excluded from the container because the ledger read builds one per task.
#[Exclude]
final readonly class BuildTask
{
    public function __construct(
        public string $project,
        public string $task,
        public bool $inFlight,
    ) {
    }
}
