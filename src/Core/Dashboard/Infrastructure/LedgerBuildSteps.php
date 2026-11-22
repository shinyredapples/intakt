<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;

// The latest step of a task decides, because a build run stops at its first failed step and a new run of the task starts again at its first step.
final readonly class LedgerBuildSteps
{
    /**
     * @return list<array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}>
     */
    public function in(RecordedEvents $events): array
    {
        /** @var array<string, array<string, array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}>> $latest */
        $latest = [];

        foreach ($events as $recorded) {
            if (LedgerEventKind::BuildStepEnded !== $recorded->event->kind) {
                continue;
            }

            $step = $this->stepOf($recorded);

            if (null === $step) {
                continue;
            }

            // Keyed by project, then task, so no name either side sends can make two keys meet; removed first, so the order of the keys is the order of the latest steps.
            unset($latest[$step['project']][$step['task']]);
            $latest[$step['project']][$step['task']] = $step;
        }

        return $this->newestFirst($latest);
    }

    /**
     * @return list<array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}>
     */
    public function latestRunOf(RecordedEvents $events, string $project, string $task): array
    {
        $run = [];

        foreach ($events as $recorded) {
            $step = LedgerEventKind::BuildStepEnded === $recorded->event->kind ? $this->stepOf($recorded) : null;

            if (null === $step || $project !== $step['project'] || $task !== $step['task']) {
                continue;
            }

            if ($this->startsNewRun($run, $step['step'])) {
                $run = [];
            }

            $run[] = $step;
        }

        return $run;
    }

    // A repeated step means another run began, because one run ends each step once; this also holds when the other run's task row was dropped as malformed.
    /**
     * @param list<array{step: string}> $run
     */
    private function startsNewRun(array $run, string $step): bool
    {
        return BuildStep::Task->value === $step || in_array($step, array_column($run, 'step'), true);
    }

    // A row whose step, outcome or failure is not one the build writes is left out, so a malformed row cannot reach the status as it was stored.
    /**
     * @return array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}|null
     */
    private function stepOf(RecordedEvent $recorded): ?array
    {
        $payload = LedgerPayload::of($recorded);
        $project = $payload['project'] ?? null;
        $task = $payload['task'] ?? null;
        $step = is_string($payload['step'] ?? null) ? BuildStep::tryFrom($payload['step']) : null;
        $outcome = is_string($payload['outcome'] ?? null) ? BuildStepOutcome::tryFrom($payload['outcome']) : null;
        $failure = $payload['failure'] ?? null;

        if (!is_string($project) || '' === $project || !is_string($task) || '' === $task || null === $step || null === $outcome) {
            return null;
        }

        // The published status schema names no asked outcome yet, so a stored one is left out rather than reported outside the schema.
        if (BuildStepOutcome::Asked === $outcome) {
            return null;
        }

        if (!is_string($failure) && null !== $failure) {
            return null;
        }

        if ((BuildStepOutcome::Failed === $outcome) !== (null !== $failure)) {
            return null;
        }

        return ['project' => $project, 'task' => $task, 'step' => $step->value, 'outcome' => $outcome->value, 'failure' => $failure, 'ended_at' => $recorded->recordedAt->format(DATE_ATOM)];
    }

    /**
     * @param array<array-key, array<array-key, array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}>> $latest
     *
     * @return list<array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}>
     */
    private function newestFirst(array $latest): array
    {
        return array_merge(...array_map(array_reverse(...), array_map(array_values(...), array_values($latest))));
    }
}
