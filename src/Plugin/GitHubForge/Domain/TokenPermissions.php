<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class TokenPermissions
{
    /**
     * @return list<WorkflowFinding>
     */
    public function findings(WorkflowEntry $permissions): array
    {
        $value = $permissions->value;
        if ($value instanceof WorkflowMapping) {
            return array_values(array_filter(array_map($this->scope(...), $value->entries)));
        }

        return match ($value instanceof WorkflowScalar ? $value->text : null) {
            'read-all' => [],
            'write-all' => [new WorkflowFinding(WorkflowRisk::Permissions, $permissions->line, 'the token may write everything (write-all)')],
            default => [new WorkflowFinding(WorkflowRisk::Unreadable, $permissions->line, 'permissions in a form the scan does not read')],
        };
    }

    private function scope(WorkflowEntry $scope): ?WorkflowFinding
    {
        return match ($scope->value instanceof WorkflowScalar ? $scope->value->text : null) {
            'read', 'none' => null,
            'write' => new WorkflowFinding(WorkflowRisk::Permissions, $scope->line, sprintf('the token may write %s', $scope->key)),
            default => new WorkflowFinding(WorkflowRisk::Unreadable, $scope->line, sprintf('the permission %s in a form the scan does not read', $scope->key)),
        };
    }
}
