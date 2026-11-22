<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use NoDiscard;

// A change may push without the owner only inside this envelope, because a pushed workflow runs before any review: hosted runners, a read-only token, no secrets, no trigger with the base repository's rights.
final readonly class WorkflowFileScan
{
    private const array WORKFLOW_KEYS = ['on', 'jobs', 'permissions'];

    private const array JOB_KEYS = ['permissions', 'runs-on', 'uses', 'secrets'];

    public function __construct(
        private WorkflowReader $reader,
        private TokenPermissions $permissions,
        private SecretReferences $secrets,
    ) {
    }

    /**
     * @return list<WorkflowFinding>
     */
    #[NoDiscard]
    public function scan(string $workflowText): array
    {
        try {
            $workflow = $this->reader->workflow($workflowText);
        } catch (WorkflowFormNotRead $unread) {
            return [new WorkflowFinding(WorkflowRisk::Unreadable, $unread->lineNumber, $unread->getMessage())];
        }

        $findings = [...$this->keysInAnotherCase($workflow, self::WORKFLOW_KEYS), ...$this->triggers($workflow), ...$this->jobs($workflow), ...$this->secrets->in($workflow)];
        usort($findings, static fn (WorkflowFinding $one, WorkflowFinding $other): int => $one->line <=> $other->line);

        return $findings;
    }

    // Unreadable rather than read in any case, because how GitHub compares a key's case is not settled here, so a key that matches only ignoring case fails closed.
    /**
     * @param list<string> $keys
     *
     * @return list<WorkflowFinding>
     */
    private function keysInAnotherCase(WorkflowMapping $mapping, array $keys): array
    {
        $findings = [];
        foreach ($keys as $key) {
            foreach ($mapping->entriesInAnotherCase($key) as $written) {
                $findings[] = new WorkflowFinding(WorkflowRisk::Unreadable, $written->line, sprintf('the key %s matches %s only when case is ignored, and the scan does not settle how GitHub compares case', $written->key, $key));
            }
        }

        return $findings;
    }

    /**
     * @return list<WorkflowFinding>
     */
    private function triggers(WorkflowMapping $workflow): array
    {
        $on = $workflow->entry('on');
        $findings = match (true) {
            !$on instanceof WorkflowEntry && [] !== $workflow->entriesInAnotherCase('on') => [],
            !$on instanceof WorkflowEntry => [new WorkflowFinding(WorkflowRisk::Unreadable, $workflow->line, 'the workflow names no trigger (on)')],
            $on->value instanceof WorkflowMapping => array_map(fn (WorkflowEntry $event): ?WorkflowFinding => $this->trigger($event->key, $event->line), $on->value->entries),
            $on->value instanceof WorkflowSequence => array_map($this->listedTrigger(...), $on->value->items),
            default => [$this->trigger($on->value->text, $on->line)],
        };

        return array_values(array_filter($findings));
    }

    private function listedTrigger(WorkflowScalar|WorkflowMapping|WorkflowSequence $event): ?WorkflowFinding
    {
        return $event instanceof WorkflowScalar
            ? $this->trigger($event->text, $event->line)
            : new WorkflowFinding(WorkflowRisk::Unreadable, $event->line, 'a trigger that is not a single name');
    }

    private function trigger(string $event, int $line): ?WorkflowFinding
    {
        return match (true) {
            '' === $event => new WorkflowFinding(WorkflowRisk::Unreadable, $line, 'an empty trigger'),
            null === ContainedTrigger::tryFrom($event) => new WorkflowFinding(WorkflowRisk::Trigger, $line, sprintf('the trigger %s may run with the base repository\'s rights', $event)),
            default => null,
        };
    }

    /**
     * @return list<WorkflowFinding>
     */
    private function jobs(WorkflowMapping $workflow): array
    {
        $jobs = $workflow->entry('jobs');
        if (!$jobs instanceof WorkflowEntry && [] !== $workflow->entriesInAnotherCase('jobs')) {
            return [];
        }

        if (!$jobs instanceof WorkflowEntry || !$jobs->value instanceof WorkflowMapping) {
            return [new WorkflowFinding(WorkflowRisk::Unreadable, $jobs->line ?? $workflow->line, 'the workflow names no jobs')];
        }

        $inherited = $workflow->entry('permissions');
        $findings = $inherited instanceof WorkflowEntry ? $this->permissions->findings($inherited) : [];
        foreach ($jobs->value->entries as $job) {
            $findings = [...$findings, ...$this->job($job, $inherited)];
        }

        return $findings;
    }

    /**
     * @return list<WorkflowFinding>
     */
    private function job(WorkflowEntry $job, ?WorkflowEntry $inherited): array
    {
        if (!$job->value instanceof WorkflowMapping) {
            return [new WorkflowFinding(WorkflowRisk::Unreadable, $job->line, sprintf('the job %s is not a mapping of keys', $job->key))];
        }

        $own = $job->value->entry('permissions');

        return [...$this->keysInAnotherCase($job->value, self::JOB_KEYS), ...$this->runner($job->key, $job->value), ...match (true) {
            $own instanceof WorkflowEntry => $this->permissions->findings($own),
            !$inherited instanceof WorkflowEntry => [new WorkflowFinding(WorkflowRisk::Permissions, $job->line, sprintf('the job %s leaves its token\'s permissions to the repository default, which may write', $job->key))],
            default => [],
        }];
    }

    /**
     * @return list<WorkflowFinding>
     */
    private function runner(string $job, WorkflowMapping $definition): array
    {
        $called = $definition->entry('uses');
        if ($called instanceof WorkflowEntry) {
            return [new WorkflowFinding(WorkflowRisk::Unreadable, $called->line, sprintf('the job %s calls another workflow, which this scan does not read', $job))];
        }

        $runsOn = $definition->entry('runs-on');
        $findings = match (true) {
            !$runsOn instanceof WorkflowEntry && [] !== $definition->entriesInAnotherCase('runs-on') => [],
            !$runsOn instanceof WorkflowEntry => [new WorkflowFinding(WorkflowRisk::Unreadable, $definition->line, sprintf('the job %s names no runner (runs-on)', $job))],
            $runsOn->value instanceof WorkflowMapping => [new WorkflowFinding(WorkflowRisk::Runner, $runsOn->line, sprintf('the job %s picks its runner by group or labels', $job)), ...$this->expressions($job, $runsOn->value)],
            $runsOn->value instanceof WorkflowSequence => array_map(fn (WorkflowScalar|WorkflowMapping|WorkflowSequence $label): ?WorkflowFinding => $this->label($job, $label), $runsOn->value->items),
            default => [$this->label($job, $runsOn->value)],
        };

        return array_values(array_filter($findings));
    }

    private function label(string $job, WorkflowScalar|WorkflowMapping|WorkflowSequence $label): ?WorkflowFinding
    {
        return match (true) {
            // A blank label is no name either, because trimmed for the detail it would read as "runs on , not a GitHub-hosted runner label".
            !$label instanceof WorkflowScalar || '' === trim($label->text) => new WorkflowFinding(WorkflowRisk::Unreadable, $label->line, sprintf('a runner label of the job %s that is not a single name', $job)),
            // Unreadable rather than a runner finding, because the scan never reads a variable's value: an unset variable is a read that failed, not a hosted runner.
            str_contains($label->text, '${{') => new WorkflowFinding(WorkflowRisk::Unreadable, $label->line, sprintf('the job %s takes its runner from an expression the scan does not read, whose value can change without a commit', $job)),
            null === HostedRunnerLabel::tryFrom($label->text) => new WorkflowFinding(WorkflowRisk::Runner, $label->line, sprintf('the job %s runs on %s, not a GitHub-hosted runner label', $job, $this->oneLine($label->text))),
            default => null,
        };
    }

    // A label from a block value keeps its line breaks, which would split the finding's line in the command's output.
    private function oneLine(string $label): string
    {
        return str_replace("\n", '\n', trim($label));
    }

    // A group or label is still read from a variable when it sits in a mapping, so an expression anywhere in it is a failed read too.
    /**
     * @return list<WorkflowFinding>
     */
    private function expressions(string $job, WorkflowScalar|WorkflowMapping|WorkflowSequence $value): array
    {
        return match (true) {
            $value instanceof WorkflowMapping => array_merge([], ...array_map(fn (WorkflowEntry $entry): array => $this->expressions($job, $entry->value), $value->entries)),
            $value instanceof WorkflowSequence => array_merge([], ...array_map(fn (WorkflowScalar|WorkflowMapping|WorkflowSequence $item): array => $this->expressions($job, $item), $value->items)),
            str_contains($value->text, '${{') => [new WorkflowFinding(WorkflowRisk::Unreadable, $value->line, sprintf('the job %s takes its runner from an expression the scan does not read, whose value can change without a commit', $job))],
            default => [],
        };
    }
}
