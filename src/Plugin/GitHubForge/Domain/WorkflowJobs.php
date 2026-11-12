<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Built per file, because whether a job lacks a runner or a token declaration is known only at the file's end.
#[Exclude]
final class WorkflowJobs
{
    private const string WORKFLOW = '';
    private const array READ_ONLY_PERMISSIONS = ['{}', 'read-all'];
    private const array READ_ONLY_SCOPE = ['read', 'none'];

    /** @var array<string, int> */
    private array $jobs = [];
    /** @var array<string, true> */
    private array $runners = [];
    /** @var array<string, true> */
    private array $callers = [];
    /** @var array<string, true> */
    private array $permissions = [];
    /** @var array<string, int> */
    private array $scopesAwaited = [];

    public function __construct(
        private readonly WorkflowFindings $findings,
    ) {
    }

    /** @param list<string> $path */
    public function record(int $number, array $path): void
    {
        if ($path[0] !== 'jobs' || count($path) < 2 || count($path) > 3) {
            return;
        }
        $job = $path[1];
        match ($path[2] ?? null) {
            null => $this->jobs[$job] = $number,
            'runs-on' => $this->runners[$job] = true,
            'uses' => $this->callers[$job] = true,
            'permissions' => $this->permissions[$job] = true,
            default => null,
        };
    }

    public function readWorkflowPermissions(int $number, string $value): void
    {
        $this->readPermissions($number, self::WORKFLOW, $value);
    }

    public function readWorkflowScope(int $number, WorkflowNode $scope): void
    {
        $this->readScope($number, self::WORKFLOW, $scope);
    }

    public function readPermissions(int $number, string $job, string $value): void
    {
        $this->permissions[$job] = true;
        match (true) {
            $value === '' => $this->scopesAwaited[$job] = $number,
            in_array($value, self::READ_ONLY_PERMISSIONS, true) => null,
            $value === 'write-all' => $this->findings->report($number, WorkflowRiskKind::WritableToken, 'permissions: write-all'),
            default => $this->findings->report($number, WorkflowRiskKind::Unreadable, sprintf('permissions this scan does not read: %s', $value)),
        };
    }

    public function readScope(int $number, string $job, WorkflowNode $scope): void
    {
        unset($this->scopesAwaited[$job]);
        $value = $scope->value->text();
        match (true) {
            in_array($value, self::READ_ONLY_SCOPE, true) => null,
            $value === 'write' => $this->findings->report($number, WorkflowRiskKind::WritableToken, sprintf('the token may write %s', $scope->key)),
            default => $this->findings->report($number, WorkflowRiskKind::Unreadable, sprintf('a permission this scan does not read: %s: %s', $scope->key, $value)),
        };
    }

    public function finish(): void
    {
        foreach (array_diff_key($this->jobs, $this->callers) as $job => $line) {
            $this->checkJob($job, $line);
        }
        foreach ($this->scopesAwaited as $line) {
            $this->findings->report($line, WorkflowRiskKind::Unreadable, 'permissions without a scope');
        }
    }

    private function checkJob(string $job, int $line): void
    {
        if (!isset($this->runners[$job])) {
            $this->findings->report($line, WorkflowRiskKind::Unreadable, sprintf('the job %s names no runs-on', $job));
        }
        if (!isset($this->permissions[self::WORKFLOW]) && !isset($this->permissions[$job])) {
            $this->findings->report($line, WorkflowRiskKind::WritableToken, sprintf('the job %s declares no permissions, so its token gets the repository\'s default, which may write', $job));
        }
    }
}
