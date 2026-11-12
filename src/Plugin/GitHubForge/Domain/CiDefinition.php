<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

// A file a workflow runs runs on the runner as the workflow does, so changing it is a CI change like editing the workflow.
final readonly class CiDefinition
{
    private const string GITHUB_FOLDER = '.github/';
    // Every Dockerfile counts, because a workflow can build an image from one in its build context without naming it.
    private const string DOCKERFILE = '/(?:^|\/)(?:Dockerfile(?:\..+)?|[^\/]+\.Dockerfile)$/';

    /**
     * @param list<WorkflowFile> $workflows
     * @param list<string>       $changedPaths
     *
     * @return list<string>
     */
    public function changedAmong(array $workflows, array $changedPaths): array
    {
        return array_values(array_filter($changedPaths, fn (string $path): bool => $this->isPartOf($workflows, $path)));
    }

    /** @param list<WorkflowFile> $workflows */
    private function isPartOf(array $workflows, string $path): bool
    {
        if (str_starts_with($path, self::GITHUB_FOLDER) || 1 === preg_match(self::DOCKERFILE, $path)) {
            return true;
        }

        return array_any($workflows, fn (WorkflowFile $workflow): bool => $this->names($workflow, $path));
    }

    // A workflow that cannot be read may name any path.
    private function names(WorkflowFile $workflow, string $path): bool
    {
        if (null === $workflow->content) {
            return true;
        }

        return 1 === preg_match('/(?<![\w.-])'.preg_quote($path, '/').'(?![\w.\/-])/', $workflow->content);
    }
}
