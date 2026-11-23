<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;

// Apart from the ledger read, because which rows form the latest run is a rule of its own: by the run id when the rows name one, by the order of the steps when they do not.
final readonly class LatestBuildRun
{
    /**
     * @param list<array{?string, ?array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}}> $rows
     *
     * @return list<array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}>
     */
    public static function of(array $rows): array
    {
        $named = self::namedRunsOf($rows);

        // Every named run is newer than the rows written before runs were named, so those count only for a task without a named row.
        if ([] === $named) {
            return self::lastUnnamedRunOf($rows);
        }

        // The build takes a run's id time-ordered before it reads the task, so the greatest id is the run started last, whichever run wrote the last row.
        ksort($named, SORT_STRING);

        return array_values(array_last($named));
    }

    /**
     * @param list<array{?string, ?array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}}> $rows
     *
     * @return array<string, array<string, array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}>>
     */
    private static function namedRunsOf(array $rows): array
    {
        $named = [];

        foreach ($rows as [$run, $step]) {
            if (null !== $run && null !== $step) {
                // A second row of a step in the same run is left out, because one run ends each step once, so it is not one the build writes.
                $named[$run][$step['step']] ??= $step;
            }
        }

        return $named;
    }

    /**
     * @param list<array{?string, ?array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}}> $rows
     *
     * @return list<array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string}>
     */
    private static function lastUnnamedRunOf(array $rows): array
    {
        $unnamed = [];
        $ended = false;

        foreach ($rows as [$run, $step]) {
            if (null === $step) {
                $ended = true;
            } elseif (null === $run) {
                $unnamed = !$ended && self::continues($unnamed, $step) ? [...$unnamed, $step] : [$step];
                $ended = false;
            }
        }

        return $unnamed;
    }

    // A row written before runs were named continues the run before it only when that run's last step passed and the row's step comes later, because a run stops at its first failed step and runs its steps in order.
    /**
     * @param list<array{step: string, outcome: string}> $run
     * @param array{step: string}                        $step
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
