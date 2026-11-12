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
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
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
#[UsesClass(Vouching::class)]
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
        $plan = $this->plan([$this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #2'])), $this->row(Issues::task(2))], [2 => 'the build ran out of rework rounds']);

        self::assertSame([], $this->numbers($plan->ready));
        self::assertSame(['depends on #2, which failed for good: the build ran out of rework rounds', 'failed for good: the build ran out of rework rounds'], array_map(static fn (HeldIssue $held): string => $held->reason, $plan->held));
    }

    public function testATaskWaitingOnAClosedTaskThatFailedForGoodIsHeldWithTheReason(): void
    {
        $plan = $this->plan([$this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #2']))], [2 => 'cannot build']);

        self::assertSame('depends on #2, which failed for good: cannot build', $plan->held[0]->reason);
    }

    public function testATaskWaitingOnItsOwnPullRequestIsNotHeld(): void
    {
        self::assertSame([1], $this->readyOf([
            $this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #50'])),
            $this->row(Issues::pullRequest(50, 'Closes #1')),
        ]));
    }

    public function testAWaitOnAPullRequestIsAWaitOnTheTaskItCloses(): void
    {
        $waiting = $this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #50']));
        $pullRequest = $this->row(Issues::pullRequest(50, 'Closes #3'));

        self::assertSame('waits for #3', $this->plan([$waiting, $pullRequest, $this->row(Issues::task(3, ['bug']))])->held[0]->reason);
        self::assertSame([1], $this->readyOf([$waiting, $pullRequest]));
    }

    public function testAWaitOnAPullRequestWhoseTaskFailedForGoodIsHeldWithTheReason(): void
    {
        $plan = $this->plan([$this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #50'])), $this->row(Issues::pullRequest(50, 'Closes #3'))], [3 => 'cannot build']);

        self::assertSame('depends on #3, which failed for good: cannot build', $plan->held[0]->reason);
    }

    public function testAWaitOnAPullRequestOfAnAccountWithoutWriteAccessLastsUntilThePullRequestCloses(): void
    {
        $plan = $this->plan([$this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #50'])), $this->row(Issues::pullRequest(50, 'Closes #1', 'mallory'), trusted: false)]);

        self::assertSame('waits for #50', $plan->held[0]->reason);
    }

    public function testAWaitOnAPullRequestWithoutAClosingKeywordLastsUntilThePullRequestCloses(): void
    {
        $plan = $this->plan([$this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #50'])), $this->row(Issues::pullRequest(50, 'A refactoring.'))]);

        self::assertSame('waits for #50', $plan->held[0]->reason);
    }

    public function testTheLowestTrustedOpenPullRequestClosingATaskIsItsRework(): void
    {
        $plan = $this->plan([
            $this->row(Issues::task(1)),
            $this->row(Issues::pullRequest(52, 'Fixes #1')),
            $this->row(Issues::pullRequest(51, 'Fixes #1')),
            $this->row(Issues::pullRequest(50, 'Fixes #1', 'mallory'), trusted: false),
        ]);

        self::assertSame([1 => 51], $plan->pullRequests);
    }

    public function testAnEligibilityLabelAnAccountWithoutWriteAccessAppliedMakesNoTask(): void
    {
        self::assertSame([], $this->readyOf([$this->row(Issues::task(1), vouched: [])]));
    }

    public function testAPriorityLabelAnAccountWithoutWriteAccessAppliedDoesNotCount(): void
    {
        $ready = $this->readyOf([
            $this->row(Issues::task(1, ['intakt', 'priority:urgent']), vouched: ['intakt']),
            $this->row(Issues::task(2, ['intakt', 'priority:high'])),
        ]);

        self::assertSame([2, 1], $ready);
    }

    public function testThePriorityLabelAppliedLastCounts(): void
    {
        $ready = $this->readyOf([
            $this->row(Issues::task(1, ['intakt', 'priority:high'])),
            $this->row(Issues::task(2, ['intakt', 'priority:urgent', 'priority:low']), vouched: ['intakt', 'priority:low', 'priority:urgent']),
        ]);

        self::assertSame([2, 1], $ready);
    }

    public function testAVouchedLabelTheItemNoLongerCarriesDoesNotCount(): void
    {
        self::assertSame(Priority::Normal, $this->row(Issues::task(1), vouched: ['intakt', 'priority:urgent'])->priority());
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

    public function testAPullRequestOfAnAccountWithoutWriteAccessDoesNotReorderTheQueue(): void
    {
        $ready = $this->readyOf([
            $this->row(Issues::task(1, ['intakt', 'priority:high'])),
            $this->row(Issues::task(2, ['intakt', 'priority:low'])),
            $this->row(Issues::pullRequest(50, 'Fixes #2', 'mallory'), trusted: false),
        ]);

        self::assertSame([1, 2], $ready);
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

    public function testOfTwoPriorityLabelsTheOneAppliedLastCounts(): void
    {
        self::assertSame(Priority::Urgent, Priority::of(['priority:low', 'priority:urgent']));
        self::assertSame(Priority::Low, Priority::of(['priority:urgent', 'priority:low']));
        self::assertSame(Priority::Normal, Priority::of(['intakt', 'priority:unknown']));
    }

    public function testAnotherEligibilityLabelIsTheProjectsOwn(): void
    {
        $plan = new IntakeRules('build')->planOf([$this->row(Issues::task(1, ['intakt'])), $this->row(Issues::task(2, ['build']))], []);

        self::assertSame([2], $this->numbers($plan->ready));
    }

    public function testOnlyATaskCandidateOrAPullRequestThatClosesATaskNeedsItsHistory(): void
    {
        $rules = new IntakeRules('intakt');

        self::assertSame(
            [true, false, false, true, false],
            [
                $rules->needsHistory(false, ['intakt'], []),
                $rules->needsHistory(false, ['intakt', 'idea'], []),
                $rules->needsHistory(false, ['bug'], []),
                $rules->needsHistory(true, [], [4]),
                $rules->needsHistory(true, ['intakt'], []),
            ],
        );
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
     * @param list<QueuedIssue>  $rows
     * @param array<int, string> $failures
     */
    private function plan(array $rows, array $failures = []): IntakePlan
    {
        return new IntakeRules('intakt')->planOf($rows, $failures);
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

    /**
     * @param list<string>|null $vouched the labels an account with write access applied last, by default every label
     */
    private function row(ListedIssue $listed, bool $trusted = true, ?array $vouched = null): QueuedIssue
    {
        $references = new IssueReferences();
        $row = new QueuedIssue('alpha', $listed->number, new ItemReading($listed, $references->waitsFor($listed->body), $references->closedBy($listed->body)));
        $row->vouch(new Vouching($listed->author, $trusted, $vouched ?? $listed->labels));

        return $row;
    }
}
