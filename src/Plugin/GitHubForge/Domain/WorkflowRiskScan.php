<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use NoDiscard;

final readonly class WorkflowRiskScan
{
    // The caller passes the head's, main's and the merged workflows together: a finding in any of them makes the change risky.
    #[NoDiscard]
    public function scan(WorkflowFile ...$files): WorkflowRisk
    {
        $findings = [];
        foreach ($files as $file) {
            $findings = [...$findings, ...new WorkflowFileScan($file)->findings()];
        }

        return new WorkflowRisk($findings);
    }
}
