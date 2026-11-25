<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Maintainer\Domain;

use DateTimeImmutable;
use Intakt\Core\Maintainer\Domain\FlowReport;
use Intakt\Core\Maintainer\Domain\HandOver;
use Intakt\Core\Maintainer\Domain\OverdueMilestone;
use Intakt\Core\Maintainer\Domain\PriorityShare;
use Intakt\Plugin\WorkSource\Contract\Holder;
use Intakt\Plugin\WorkSource\Contract\OpenTask;
use Intakt\Plugin\WorkSource\Contract\OpenWork;
use Intakt\Plugin\WorkSource\Contract\TaskPriority;
use Intakt\Plugin\WorkSource\Contract\Wait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlowReport::class)]
#[UsesClass(HandOver::class)]
#[UsesClass(OverdueMilestone::class)]
#[UsesClass(PriorityShare::class)]
#[UsesClass(OpenWork::class)]
#[UsesClass(OpenTask::class)]
#[UsesClass(Wait::class)]
final class FlowReportTest extends TestCase
{
    private const float THIRD = 1 / 3;

    public function testAHandOverHoldsEveryOpenTaskBehindItThroughAThreeLevelUmbrellaChain(): void
    {
        $report = $this->report([
            $this->task(1, Holder::Person),
            $this->task(2, Holder::Nothing, [new Wait(1, true)]),
            $this->task(3, Holder::Nothing, [new Wait(2, true)]),
            $this->task(4, Holder::Nothing, [new Wait(3, true)]),
        ]);

        self::assertSame([[1, 3]], $this->handOvers($report));
    }

    public function testAPartLineInACommentByAnUntrustedAccountAddsNothingToWhatAHandOverHolds(): void
    {
        $report = $this->report([
            $this->task(1, Holder::Session),
            $this->task(2, Holder::Nothing, [new Wait(1, true)]),
            $this->task(3, Holder::Nothing, [new Wait(2, false)]),
            $this->task(4, Holder::Nothing, [new Wait(1, false)]),
        ]);

        self::assertSame([[1, 1]], $this->handOvers($report));
    }

    public function testAWaitLineByIntaktsOwnAppCountsLikeOneByAWriter(): void
    {
        $report = $this->report([$this->task(1, Holder::Session), $this->task(2, Holder::Nothing, [new Wait(1, true)])]);

        self::assertSame([[1, 1]], $this->handOvers($report));
    }

    public function testHandOversAreRankedMostHeldFirstThenByNumber(): void
    {
        $report = $this->report([
            $this->task(5, Holder::Person),
            $this->task(6, Holder::Session),
            $this->task(7, Holder::Session, [new Wait(6, true)]),
            $this->task(8, Holder::Nothing, [new Wait(6, true)]),
            $this->task(9, Holder::Nothing, [new Wait(5, true)]),
            $this->task(10, Holder::Nothing, [new Wait(5, true)]),
        ]);

        self::assertSame([[5, 2], [6, 2], [7, 0]], $this->handOvers($report));
    }

    public function testACycleOfWaitsCountsEachHeldTaskOnceAndEnds(): void
    {
        $report = $this->report([
            $this->task(1, Holder::Session, [new Wait(3, true)]),
            $this->task(2, Holder::Nothing, [new Wait(1, true), new Wait(1, true)]),
            $this->task(3, Holder::Nothing, [new Wait(2, true)]),
        ]);

        self::assertSame([[1, 2]], $this->handOvers($report));
    }

    public function testATaskWaitingOnNothingIsNoHandOver(): void
    {
        $report = $this->report([$this->task(1, Holder::Nothing), $this->task(2, Holder::Nothing, [new Wait(1, true)])]);

        self::assertSame([], $report->handOvers);
    }

    public function testOpenTasksInAMilestonePastItsDueDateAreCountedForThatMilestone(): void
    {
        $report = $this->report([
            $this->task(1, Holder::Nothing, [], TaskPriority::Normal, '2026-11-01'),
            $this->task(2, Holder::Nothing, [], TaskPriority::Normal, '2026-11-01'),
            $this->task(3, Holder::Nothing, [], TaskPriority::Normal, '2026-10-01'),
        ]);

        self::assertSame([['2026-10-01', 1], ['2026-11-01', 2]], array_map(static fn (OverdueMilestone $m): array => [$m->due->format('Y-m-d'), $m->openTasks], $report->overdueMilestones));
    }

    public function testAMilestoneThatIsNotYetDueIsNotListedAsOverdue(): void
    {
        $report = $this->report([$this->task(1, Holder::Nothing, [], TaskPriority::Normal, '2026-12-01'), $this->task(2, Holder::Nothing)]);

        self::assertSame([], $report->overdueMilestones);
    }

    public function testAnEmptyMilestonePastItsDueDateIsListedAsOneToClose(): void
    {
        $report = FlowReport::of(new OpenWork([], [new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-12-01')]), new DateTimeImmutable('2026-11-20'), self::THIRD);

        self::assertSame(['2026-11-01'], array_map(static fn (DateTimeImmutable $due): string => $due->format('Y-m-d'), $report->milestonesToClose));
    }

    public function testThePriorityMixGivesEachPrioritysShareOfTheOpenTasks(): void
    {
        $report = $this->report([
            $this->task(1, Holder::Nothing, [], TaskPriority::Normal),
            $this->task(2, Holder::Nothing, [], TaskPriority::Normal),
            $this->task(3, Holder::Nothing, [], TaskPriority::High),
            $this->task(4, Holder::Nothing, [], TaskPriority::Low),
        ]);

        self::assertSame(['urgent' => 0.0, 'high' => 0.25, 'normal' => 0.5, 'low' => 0.25], array_column(array_map(static fn (PriorityShare $s): array => [$s->priority->value, $s->share], $report->priorityMix), 1, 0));
    }

    public function testMoreThanAThirdUrgentIsAFindingAndExactlyAThirdIsNot(): void
    {
        $over = $this->report([$this->task(1, Holder::Nothing, [], TaskPriority::Urgent), $this->task(2, Holder::Nothing, [], TaskPriority::Urgent), $this->task(3, Holder::Nothing, [], TaskPriority::Low), $this->task(4, Holder::Nothing, [], TaskPriority::Low), $this->task(5, Holder::Nothing, [], TaskPriority::Low)]);
        $exactly = $this->report([$this->task(1, Holder::Nothing, [], TaskPriority::Urgent), $this->task(2, Holder::Nothing, [], TaskPriority::Low), $this->task(3, Holder::Nothing, [], TaskPriority::Low)]);

        self::assertTrue($over->tooManyUrgent);
        self::assertFalse($exactly->tooManyUrgent);
    }

    public function testNoOpenTaskGivesNoMixAndNoFinding(): void
    {
        $report = $this->report([]);

        self::assertSame([], $report->priorityMix);
        self::assertFalse($report->tooManyUrgent);
    }

    /**
     * @param list<OpenTask> $tasks
     */
    private function report(array $tasks): FlowReport
    {
        return FlowReport::of(new OpenWork($tasks, []), new DateTimeImmutable('2026-11-20'), self::THIRD);
    }

    /**
     * @param list<Wait> $waits
     */
    private function task(int $number, Holder $holder, array $waits = [], TaskPriority $priority = TaskPriority::Normal, ?string $due = null): OpenTask
    {
        return new OpenTask($number, 'Task '.$number, $priority, null === $due ? null : new DateTimeImmutable($due), $holder, $waits);
    }

    /**
     * @return list<array{int, int}>
     */
    private function handOvers(FlowReport $report): array
    {
        return array_map(static fn (HandOver $h): array => [$h->task->number, $h->openTasksBehind], $report->handOvers);
    }
}
