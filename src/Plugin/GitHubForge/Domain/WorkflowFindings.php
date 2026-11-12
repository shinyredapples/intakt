<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Built per file, because it collects that file's findings while it is read.
#[Exclude]
final class WorkflowFindings
{
    /** @var list<WorkflowRiskFinding> */
    private array $findings = [];

    public function __construct(
        private readonly string $path,
    ) {
    }

    public function report(int $line, WorkflowRiskKind $kind, string $detail): void
    {
        $this->findings[] = new WorkflowRiskFinding($this->path, $line, $kind, $detail);
    }

    /** @return list<WorkflowRiskFinding> */
    public function all(): array
    {
        return $this->findings;
    }
}
