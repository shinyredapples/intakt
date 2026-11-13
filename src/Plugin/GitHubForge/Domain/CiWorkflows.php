<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

// Each side counts, because GitHub runs the head's workflows on the push and the merged ones on the merge, and main's decide what a script the change edits runs with.
final readonly class CiWorkflows
{
    /**
     * @param list<WorkflowFile> $head
     * @param list<WorkflowFile> $main
     * @param list<WorkflowFile> $merged
     * @param list<string>       $changedPaths
     */
    public function __construct(
        public array $head,
        public array $main,
        public array $merged,
        public array $changedPaths,
    ) {
    }

    /**
     * @return list<WorkflowFile>
     */
    public function of(WorkflowSide $side): array
    {
        return match ($side) {
            WorkflowSide::Head => $this->head,
            WorkflowSide::Main => $this->main,
            WorkflowSide::Merged => $this->merged,
        };
    }

    /**
     * @return list<WorkflowFile>
     */
    public function all(): array
    {
        return [...$this->head, ...$this->main, ...$this->merged];
    }
}
