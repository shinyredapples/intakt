<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Symfony\Component\Uid\Uuid;

// The latest step of a task's latest run decides, because a build run stops at its first failed step, a new run of the task starts again at its first step, and an older run can still write after a newer one started.
final readonly class LedgerBuildSteps
{
    /**
     * @return list<array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}>
     */
    public function in(RecordedEvents $events): array
    {
        $latest = [];

        foreach ($this->rowsByTask($events) as $project => $tasks) {
            foreach ($tasks as $task => $rows) {
                $latest[$project][$task] = array_last(LatestBuildRun::of($rows));
            }
        }

        return $this->newestFirst($latest, $events);
    }

    /**
     * @return list<array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}>
     */
    public function latestRunOf(RecordedEvents $events, string $project, string $task): array
    {
        return LatestBuildRun::of($this->rowsOf($events, $project, $task));
    }

    /**
     * @return list<array{?string, ?array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}}>
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
     * @return array{?string, ?array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}}|null
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
        return null !== $run || BuildStep::Task->value === PayloadFields::of($recorded)->text('step') ? [$run, null] : null;
    }

    // Null for a row written before runs were named, and false for a run that is no UUID (an explicit null included): the build never writes one, so the row's run cannot be told and the row is left out.
    private function runOf(RecordedEvent $recorded): string|false|null
    {
        $payload = PayloadFields::of($recorded);

        if (!$payload->has('run')) {
            return null;
        }

        $run = $payload->text('run');

        return null !== $run && Uuid::isValid($run) ? strtolower($run) : false;
    }

    // A row whose step, outcome or failure is not one the build writes is left out, so a malformed row cannot reach the status as it was stored.
    /**
     * @return array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}|null
     */
    private function stepOf(RecordedEvent $recorded): ?array
    {
        $payload = PayloadFields::of($recorded);
        $project = $payload->text('project');
        $task = $payload->text('task');
        $step = BuildStep::tryFrom($payload->text('step') ?? '');
        $outcome = BuildStepOutcome::tryFrom($payload->text('outcome') ?? '');
        $failure = $payload->text('failure');

        if (null === $project || '' === $project || null === $task || '' === $task || null === $step || null === $outcome) {
            return null;
        }

        if (null === $failure && !$payload->lacks('failure')) {
            return null;
        }

        if (!$this->isWrittenByTheBuild($step, $outcome, $failure)) {
            return null;
        }

        return ['project' => $project, 'task' => $task, 'step' => $step->value, 'outcome' => $outcome->value, 'failure' => $failure, 'ended_at' => $recorded->recordedAt->format(DATE_ATOM), 'run' => $this->namedRunOf($recorded), 'final' => FinalBuildStep::is($step, $outcome, $payload)];
    }

    private function isRowOf(RecordedEvent $recorded, string $project, string $task): bool
    {
        $payload = PayloadFields::of($recorded);

        return $project === $payload->text('project') && $task === $payload->text('task');
    }

    // Only a failed step has a failure text, and only the build job asks, so any other pairing is no row the build writes.
    private function isWrittenByTheBuild(BuildStep $step, BuildStepOutcome $outcome, ?string $failure): bool
    {
        if ((BuildStepOutcome::Failed === $outcome) !== (null !== $failure)) {
            return false;
        }

        return BuildStepOutcome::Asked !== $outcome || BuildStep::BuildJob === $step;
    }

    // Null also for a run that is no UUID, because no parked question can belong to such a row; rowOf() leaves it out of every run.
    private function namedRunOf(RecordedEvent $recorded): ?string
    {
        $run = $this->runOf($recorded);

        return false === $run ? null : $run;
    }

    // Keyed by project, then task, so no name either side sends can make two keys meet.
    /**
     * @return array<array-key, array<array-key, list<array{?string, ?array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}}>>>
     */
    private function rowsByTask(RecordedEvents $events): array
    {
        $rows = [];

        foreach ($events as $recorded) {
            if (LedgerEventKind::BuildStepEnded !== $recorded->event->kind) {
                continue;
            }

            $payload = PayloadFields::of($recorded);
            $project = $payload->text('project') ?? '';
            $task = $payload->text('task') ?? '';
            $row = '' === $project || '' === $task ? null : $this->rowOf($recorded, $project, $task);

            if (null !== $row) {
                $rows[$project][$task][] = $row;
            }
        }

        return $rows;
    }

    // By where each shown step stands in the ledger, because a step an older run wrote later does not move the task.
    /**
     * @param array<array-key, array<array-key, ?array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}>> $latest
     *
     * @return list<array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}>
     */
    private function newestFirst(array $latest, RecordedEvents $events): array
    {
        /** @var array<array-key, array<array-key, array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}>> $ordered */
        $ordered = [];

        foreach ($events as $recorded) {
            $step = LedgerEventKind::BuildStepEnded === $recorded->event->kind ? $this->stepOf($recorded) : null;

            if (null !== $step && $step === ($latest[$step['project']][$step['task']] ?? null)) {
                // Removed first, so the order of the keys is the order of the shown steps.
                unset($ordered[$step['project']][$step['task']]);
                $ordered[$step['project']][$step['task']] = $step;
            }
        }

        return array_merge(...array_map(array_reverse(...), array_map(array_values(...), array_values($ordered))));
    }
}
