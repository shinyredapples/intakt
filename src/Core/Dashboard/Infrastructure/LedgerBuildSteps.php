<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Symfony\Component\Uid\Uuid;

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
        return LatestBuildRun::of($this->rowsOf($events, $project, $task));
    }

    /**
     * @return list<array{?string, ?array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}}>
     */
    private function rowsOf(RecordedEvents $events, string $project, string $task): array
    {
        $rows = [];

        foreach ($events as $recorded) {
            $row = LedgerEventKind::BuildStepEnded === $recorded->event->kind ? $this->rowOf($recorded, $project, $task) : null;

            if (null !== $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    // A row of the task with its run and step; without its step for a malformed row of a named run or a malformed task row of an unnamed run, because that row is left out but still marks where its run began.
    /**
     * @return array{?string, ?array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}}|null
     */
    private function rowOf(RecordedEvent $recorded, string $project, string $task): ?array
    {
        $run = $this->runOf($recorded);

        if (false === $run) {
            return null;
        }

        $step = $this->stepOf($recorded);

        if (null !== $step && $project === $step['project'] && $task === $step['task']) {
            return [$run, $step];
        }

        if (!$this->isRowOf($recorded, $project, $task)) {
            return null;
        }

        // Only the task row of an unnamed run, because without a run id only the task step tells that a new run began.
        return null !== $run || BuildStep::Task->value === (LedgerPayload::of($recorded)['step'] ?? null) ? [$run, null] : null;
    }

    // Null for a row written before runs were named, and false for a run that is no UUID (an explicit null included): the build never writes one, so the row's run cannot be told and the row is left out.
    private function runOf(RecordedEvent $recorded): string|false|null
    {
        $payload = LedgerPayload::of($recorded);

        if (!array_key_exists('run', $payload)) {
            return null;
        }

        return is_string($payload['run']) && Uuid::isValid($payload['run']) ? strtolower($payload['run']) : false;
    }

    private function isRowOf(RecordedEvent $recorded, string $project, string $task): bool
    {
        $payload = LedgerPayload::of($recorded);

        return $project === ($payload['project'] ?? null) && $task === ($payload['task'] ?? null);
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
