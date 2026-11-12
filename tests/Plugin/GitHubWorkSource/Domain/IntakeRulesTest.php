<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource\Domain;

use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\HeldIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\Priority;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Tests\Plugin\GitHubWorkSource\Issues;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IntakeRules::class)]
#[CoversClass(Priority::class)]
#[CoversClass(QueuedIssue::class)]
#[UsesClass(IntakePlan::class)]
#[UsesClass(HeldIssue::class)]
#[UsesClass(IssueReferences::class)]
#[UsesClass(ItemReading::class)]
final class IntakeRulesTest extends TestCase
{
    public function testAnOpenTrustedTaskWithTheEligibilityLabelIsReady(): void
    {
        self::assertSame([1], $this->readyOf([$this->row(Issues::task(1))]));
    }

    public function testATaskWithoutTheEligibilityLabelIsNotEligible(): void
    {
        self::assertSame([], $this->readyOf([$this->row(Issues::task(1, ['bug']))]));
    }

    public function testAnIdeaIsNotEligibleEvenWithTheEligibilityLabel(): void
    {
        self::assertSame([], $this->readyOf([$this->row(Issues::task(1, ['intakt', 'idea']))]));
    }

    public function testATaskOfAnUntrustedWriterOrEditorIsNotEligible(): void
    {
        self::assertSame([], $this->readyOf([$this->row(Issues::task(1), trusted: false)]));
    }

    public function testAPullRequestIsNoTask(): void
    {
        self::assertSame([], $this->readyOf([$this->row(Issues::pullRequest(1, ''))]));
    }

    public function testATaskWaitingOnAnOpenIssueIsHeldWithTheIssue(): void
    {
        $plan = $this->plan([$this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #2'])), $this->row(Issues::task(2, ['bug']))]);

        self::assertSame([], $this->numbers($plan->ready));
        self::assertSame('waits for #2', $plan->held[0]->reason);
    }

    public function testATaskWaitingOnAnIssueTheListingDoesNotShowIsReady(): void
    {
        self::assertSame([1], $this->readyOf([$this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #2']))]));
    }

    public function testATaskIsReadyOnlyWhenEveryIssueItWaitsOnIsClosed(): void
    {
        $waiting = $this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #2, #3']));
        $open = $this->row(Issues::task(3, ['bug']));

        self::assertSame([], $this->readyOf([$waiting, $open]));
        self::assertSame([1], $this->readyOf([$waiting]));
    }

    public function testATaskWaitingOnItselfIsNotHeld(): void
    {
        self::assertSame([1], $this->readyOf([$this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #1']))]));
    }

    public function testATaskWaitingOnATaskThatFailedForGoodIsHeldWithTheReason(): void
    {
        $failed = $this->row(Issues::task(2));
        $failed->failForGood('the build ran out of rework rounds');

        $plan = $this->plan([$this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #2'])), $failed]);

        self::assertSame([], $this->numbers($plan->ready));
        self::assertSame(['depends on #2, which failed for good: the build ran out of rework rounds', 'failed for good: the build ran out of rework rounds'], array_map(static fn (HeldIssue $held): string => $held->reason, $plan->held));
    }

    public function testAnUrgentTaskGoesBeforeATaskWhoseChangeIsOpen(): void
    {
        $ready = $this->readyOf([
            $this->row(Issues::task(1, ['intakt', 'priority:high'])),
            $this->row(Issues::pullRequest(50, 'Closes #1')),
            $this->row(Issues::task(2, ['intakt', 'priority:urgent'])),
        ]);

        self::assertSame([2, 1], $ready);
    }

    public function testATaskWhoseChangeIsOpenGoesBeforeAHigherPriorityTask(): void
    {
        $ready = $this->readyOf([
            $this->row(Issues::task(1, ['intakt', 'priority:high'])),
            $this->row(Issues::task(2, ['intakt', 'priority:low'])),
            $this->row(Issues::pullRequest(50, 'Fixes #2')),
        ]);

        self::assertSame([2, 1], $ready);
    }

    public function testPriorityThenMilestoneDueDateThenNumberOrderTheRest(): void
    {
        $ready = $this->readyOf([
            $this->row(Issues::task(1, ['intakt', 'priority:low'])),
            $this->row(Issues::task(2, ['intakt'])),
            $this->row(Issues::task(3, ['intakt'], with: ['due' => '2026-12-01T00:00:00Z'])),
            $this->row(Issues::task(4, ['intakt'], with: ['due' => '2026-11-20T00:00:00Z'])),
            $this->row(Issues::task(5, ['intakt', 'priority:high'])),
            $this->row(Issues::task(6, ['intakt'])),
        ]);

        self::assertSame([5, 4, 3, 2, 6, 1], $ready);
    }

    public function testTwoPriorityLabelsCountAsTheLessUrgentOne(): void
    {
        self::assertSame(Priority::Low, Priority::of(['priority:urgent', 'priority:low']));
        self::assertSame(Priority::Normal, Priority::of(['intakt']));
    }

    public function testAnotherEligibilityLabelIsTheProjectsOwn(): void
    {
        $plan = new IntakeRules('build')->planOf([$this->row(Issues::task(1, ['intakt'])), $this->row(Issues::task(2, ['build']))]);

        self::assertSame([2], $this->numbers($plan->ready));
    }

    /**
     * @param list<QueuedIssue> $rows
     *
     * @return list<int>
     */
    private function readyOf(array $rows): array
    {
        return $this->numbers($this->plan($rows)->ready);
    }

    /**
     * @param list<QueuedIssue> $rows
     */
    private function plan(array $rows): IntakePlan
    {
        return new IntakeRules('intakt')->planOf($rows);
    }

    /**
     * @param list<QueuedIssue> $rows
     *
     * @return list<int>
     */
    private function numbers(array $rows): array
    {
        return array_map(static fn (QueuedIssue $row): int => $row->number(), $rows);
    }

    private function row(ListedIssue $listed, bool $trusted = true): QueuedIssue
    {
        $references = new IssueReferences();

        return new QueuedIssue('alpha', new ItemReading($listed, $listed->author, $trusted, $references->waitsFor($listed->body), $references->closedBy($listed->body)));
    }
}
