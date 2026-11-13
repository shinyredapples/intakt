<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use NoDiscard;

// A risky CI change is never pushed before the owner approved it, so every side the forge may run is scanned, and anything not read counts as risky.
final readonly class CiScan
{
    public function __construct(
        private WorkflowFileScan $scan,
        private CiDefinition $ciDefinition,
    ) {
    }

    #[NoDiscard]
    public function of(CiWorkflows $workflows): CiScanResult
    {
        return new CiScanResult($this->findings($workflows), $this->ciDefinition->changedAmong($workflows->all(), $workflows->changedPaths));
    }

    /**
     * @return list<CiFinding>
     */
    private function findings(CiWorkflows $workflows): array
    {
        $findings = [];
        foreach (WorkflowSide::cases() as $side) {
            foreach ($workflows->of($side) as $file) {
                $findings = [...$findings, ...array_map(static fn (WorkflowFinding $finding): CiFinding => new CiFinding($side, $file->path, $finding), $this->findingsIn($file))];
            }
        }

        return $findings;
    }

    /**
     * @return list<WorkflowFinding>
     */
    private function findingsIn(WorkflowFile $file): array
    {
        return null === $file->content
            ? [new WorkflowFinding(WorkflowRisk::Unreadable, 0, sprintf('the workflow could not be read: %s', $file->notReadBecause))]
            : $this->scan->scan($file->content);
    }
}
