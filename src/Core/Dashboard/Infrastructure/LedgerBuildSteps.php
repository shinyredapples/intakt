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
    private const string NAMED = 'named:';

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
        /** @var array<string, array<string, array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}>> $named */
        $named = [];
        /** @var list<array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}> $unnamed */
        $unnamed = [];
        $ended = false;

        foreach ($this->rowsOf($events, $project, $task) as [$run, $step]) {
            if (null === $step) {
                $ended = true;
            } elseif (null !== $run) {
                // A second row of a step in the same run is left out, because one run ends each step once, so it is not one the build writes.
                $named[$run][$step['step']] ??= $step;
            } else {
                $unnamed = !$ended && $this->continues($unnamed, $step) ? [...$unnamed, $step] : [$step];
                $ended = false;
            }
        }

        if ([] === $named) {
            return $unnamed;
        }

        // The build takes a run's id time-ordered before it reads the task, so the greatest id is the run started last, whichever run wrote the last row. Every named run is newer than the rows written before runs were named.
        ksort($named, SORT_STRING);

        return array_values(array_last($named) ?? []);
    }

    /**
     * @return list<array{?string, ?array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}}>
     */
    private function rowsOf(RecordedEvents $events, string $project, string $task): array
    {
        $rows = [];

        foreach ($events as $recorded) {
            if (LedgerEventKind::BuildStepEnded !== $recorded->event->kind) {
                continue;
            }

            $run = $this->runOf($recorded);
            $step = false === $run ? null : $this->stepOf($recorded);

            if (null !== $step && $project === $step['project'] && $task === $step['task']) {
                $rows[] = [$run, $step];
            } elseif (null === $run && $this->isTaskRowOf($recorded, $project, $task)) {
                // A malformed task row of an unnamed run is left out, but it still marks where a new run began.
                $rows[] = [null, null];
            }
        }

        return $rows;
    }

    // Null for a row written before runs were named, and false for a run that is not a non-empty string (an explicit null included): the build never writes one, so the row's run cannot be told and the row is left out.
    private function runOf(RecordedEvent $recorded): string|false|null
    {
        $payload = LedgerPayload::of($recorded);

        if (!array_key_exists('run', $payload)) {
            return null;
        }

        // Prefixed, so PHP never turns a numeric id into an integer key and every key sorts as text.
        return is_string($payload['run']) && '' !== $payload['run'] ? self::NAMED.$payload['run'] : false;
    }

    private function isTaskRowOf(RecordedEvent $recorded, string $project, string $task): bool
    {
        $payload = LedgerPayload::of($recorded);

        return $project === ($payload['project'] ?? null) && $task === ($payload['task'] ?? null) && BuildStep::Task->value === ($payload['step'] ?? null);
    }

    // A row written before runs were named continues the run before it only when that run's last step passed and the row's step comes later, because a run stops at its first failed step and runs its steps in order.
    /**
     * @param list<array{step: string, outcome: string}> $run
     * @param array{step: string}                        $step
     */
    private function continues(array $run, array $step): bool
    {
        $last = array_last($run);

        if (null === $last || BuildStepOutcome::Passed->value !== $last['outcome']) {
            return false;
        }

        $order = array_flip(array_column(BuildStep::cases(), 'value'));

        return $order[$step['step']] > $order[$last['step']];
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
