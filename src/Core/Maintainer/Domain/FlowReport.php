<?php

declare(strict_types=1);

namespace Intakt\Core\Maintainer\Domain;

use DateTimeImmutable;
use Intakt\Plugin\WorkSource\Contract\Holder;
use Intakt\Plugin\WorkSource\Contract\OpenTask;
use Intakt\Plugin\WorkSource\Contract\OpenWork;
use Intakt\Plugin\WorkSource\Contract\TaskPriority;

// The three figures the weekly review reads, counted in plain code from the open tasks, so the model never counts.
final readonly class FlowReport
{
    /**
     * @param list<HandOver>          $handOvers
     * @param list<OverdueMilestone>  $overdueMilestones
     * @param list<DateTimeImmutable> $milestonesToClose
     * @param list<PriorityShare>     $priorityMix
     */
    private function __construct(
        public array $handOvers,
        public array $overdueMilestones,
        public array $milestonesToClose,
        public array $priorityMix,
        public bool $tooManyUrgent,
    ) {
    }

    // Past the threshold is a finding for the session and nothing else, because a priority label is the owner's word and plain code never relabels.
    public static function of(OpenWork $work, DateTimeImmutable $now, float $urgentThreshold): self
    {
        $mix = self::priorityMixOf($work->tasks);
        $urgent = array_find($mix, static fn (PriorityShare $share): bool => TaskPriority::Urgent === $share->priority);

        return new self(
            self::handOversOf($work->tasks),
            self::overdueMilestonesOf($work->tasks, $now),
            array_values(array_filter($work->emptyMilestones, static fn (DateTimeImmutable $due): bool => $due < $now)),
            $mix,
            null !== $urgent && $urgent->share > $urgentThreshold,
        );
    }

    // Most held first, then the lower number, because the order is the one hand-overs are offered to the Maintainer's sessions in.
    /**
     * @param list<OpenTask> $tasks
     *
     * @return list<HandOver>
     */
    private static function handOversOf(array $tasks): array
    {
        $waiting = [];
        foreach ($tasks as $task) {
            foreach ($task->waits as $wait) {
                if ($wait->byTrustedAccount) {
                    $waiting[$wait->target][] = $task->number;
                }
            }
        }

        $handOvers = array_map(
            static fn (OpenTask $task): HandOver => new HandOver($task, count(self::behind($task->number, $waiting))),
            array_values(array_filter($tasks, static fn (OpenTask $task): bool => Holder::Nothing !== $task->holder)),
        );
        usort($handOvers, static fn (HandOver $a, HandOver $b): int => [$b->openTasksBehind, $a->task->number] <=> [$a->openTasksBehind, $b->task->number]);

        return $handOvers;
    }

    // Each task once and never the one asked about, so a cycle of waits ends.
    /**
     * @param array<int, list<int>> $waiting
     *
     * @return array<int, true>
     */
    private static function behind(int $number, array $waiting): array
    {
        $seen = [$number => true];
        $queue = [$number];
        while ([] !== $queue) {
            foreach ($waiting[array_pop($queue)] ?? [] as $next) {
                if (!isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }
        unset($seen[$number]);

        return $seen;
    }

    /**
     * @param list<OpenTask> $tasks
     *
     * @return list<OverdueMilestone>
     */
    private static function overdueMilestonesOf(array $tasks, DateTimeImmutable $now): array
    {
        $open = [];
        $dues = [];
        foreach ($tasks as $task) {
            if ($task->milestoneDue instanceof DateTimeImmutable && $task->milestoneDue < $now) {
                $key = $task->milestoneDue->getTimestamp();
                $open[$key] = ($open[$key] ?? 0) + 1;
                $dues[$key] = $task->milestoneDue;
            }
        }
        ksort($open);

        return array_values(array_map(static fn (int $key): OverdueMilestone => new OverdueMilestone($dues[$key], $open[$key]), array_keys($open)));
    }

    /**
     * @param list<OpenTask> $tasks
     *
     * @return list<PriorityShare>
     */
    private static function priorityMixOf(array $tasks): array
    {
        if ([] === $tasks) {
            return [];
        }

        return array_map(static function (TaskPriority $priority) use ($tasks): PriorityShare {
            $count = count(array_filter($tasks, static fn (OpenTask $task): bool => $task->priority === $priority));

            return new PriorityShare($priority, $count, $count / count($tasks));
        }, TaskPriority::cases());
    }
}
