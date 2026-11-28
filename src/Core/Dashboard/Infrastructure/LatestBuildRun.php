<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;

// Apart from the ledger read, because which rows form the latest run is a rule of its own: the run whose first row comes last in the ledger, its rows told apart by the run id when they name one and by the order of the steps when they do not.
final readonly class LatestBuildRun
{
    /**
     * @param list<array{?string, ?array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}}> $rows
     *
     * @return list<array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}>
     */
    public static function of(array $rows): array
    {
        // In the order the runs started, because the build reads the task and records its row under a lock of the task, so the run whose first row comes later started later, whatever its id or the clock says.
        $runs = [];
        $named = [];
        $unnamed = null;

        foreach ($rows as [$run, $step]) {
            if (null !== $run) {
                // Also for a row left out as malformed, because the run's first row in the ledger is where it started, valid or not.
                $named[$run] ??= self::started($runs);
                self::addFirst($runs[$named[$run]], $step);
            } elseif (null === $step) {
                $unnamed = null;
            } elseif (null !== $unnamed && self::continues($runs[$unnamed], $step)) {
                $runs[$unnamed][$step['step']] = $step;
            } else {
                $unnamed = self::started($runs);
                $runs[$unnamed][$step['step']] = $step;
            }
        }

        return array_values(array_last($runs) ?? []);
    }

    /**
     * @param list<array<string, array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}>> $runs
     *
     * @param-out list<array<string, array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}>> $runs
     */
    private static function started(array &$runs): int
    {
        $runs[] = [];

        return array_key_last($runs);
    }

    // A second row of a step in the same run is left out, because one run ends each step once, so it is not one the build writes.
    /**
     * @param array<string, array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}> $run
     * @param ?array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}               $step
     *
     * @param-out array<string, array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}> $run
     */
    private static function addFirst(array &$run, ?array $step): void
    {
        if (null !== $step) {
            $run[$step['step']] ??= $step;
        }
    }

    // A row written before runs were named continues the run before it only when that run's last step passed and the row's step comes later, because a run stops at its first failed step and runs its steps in order.
    /**
     * @param array<string, array{step: string, outcome: string}> $run
     * @param array{step: string}                                 $step
     */
    private static function continues(array $run, array $step): bool
    {
        $last = array_last($run);

        if (null === $last || BuildStepOutcome::Passed->value !== $last['outcome']) {
            return false;
        }

        $order = array_flip(array_column(BuildStep::cases(), 'value'));

        return $order[$step['step']] > $order[$last['step']];
    }
}
