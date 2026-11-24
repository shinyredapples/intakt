<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource\Domain;

use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueLabels;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\HeldIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\Priority;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\QueueOrder;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
use Intakt\Tests\Plugin\GitHubWorkSource\Issues;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IntakeRules::class)]
#[CoversClass(Priority::class)]
#[CoversClass(QueueOrder::class)]
#[CoversClass(QueuedIssue::class)]
#[CoversClass(Vouching::class)]
#[UsesClass(IntakePlan::class)]
#[UsesClass(HeldIssue::class)]
#[UsesClass(IssueReferences::class)]
#[UsesClass(ItemReading::class)]
final class IntakeRulesTest extends TestCase
{
    // Named apart from the others, because `tom` (triage) and `mallory` (read) stand for the accounts whose word must not count.
    private const array WRITERS = ['ana', 'ben'];

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

    public function testATaskWrittenByAnAccountWithoutWriteAccessIsNotEligible(): void
    {
        self::assertSame([], $this->readyOf([$this->row(Issues::task(1, with: ['author' => 'mallory']), $this->history('ana', ['intakt' => 'ana']))]));
    }

    public function testATaskLastEditedByAnAccountWithoutWriteAccessIsNotEligible(): void
    {
        self::assertSame([], $this->readyOf([$this->row(Issues::task(1), $this->history('mallory', ['intakt' => 'ana']))]));
    }

    public function testATaskIsEligibleAgainOnceAnAccountWithWriteAccessSavesItAfterAnOutsidersEdit(): void
    {
        self::assertSame([1], $this->readyOf([$this->row(Issues::task(1), $this->history('ben', ['intakt' => 'ana']))]));
    }

    public function testATaskWhoseHistoryWasNotReadIsNotEligible(): void
    {
        self::assertSame([], $this->readyOf($this->unread(Issues::task(1))));
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

    public function testAReadyTaskWaitingOnAPullRequestDependsOnTheTasksThePullRequestCloses(): void
    {
        $plan = $this->plan([$this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #50, #4'])), $this->row(Issues::pullRequest(50, 'Closes #3'))]);

        self::assertSame([1 => [3, 4]], $plan->dependsOn);
    }

    public function testAWaitOnAPullRequestWhoseTaskFailedForGoodIsHeldWithTheReason(): void
    {
        $plan = $this->plan([$this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #50'])), $this->row(Issues::pullRequest(50, 'Closes #3'))], [3 => 'cannot build']);

        self::assertSame('depends on #3, which failed for good: cannot build', $plan->held[0]->reason);
    }

    public function testAPullRequestOfAnAccountWithoutWriteAccessIsNoWaitTarget(): void
    {
        self::assertSame([1, 2], $this->readyOf([
            $this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #50'])),
            $this->row(Issues::pullRequest(50, 'Fixes #1', 'mallory')),
            $this->row(Issues::pullRequest(51, 'Fixes #3', 'mallory')),
            $this->row(Issues::task(2, ['intakt'], with: ['body' => 'Waits for #51'])),
            $this->row(Issues::task(3, ['bug'])),
        ]));
    }

    public function testAPullRequestLastEditedByAnAccountWithoutWriteAccessIsNoWaitTarget(): void
    {
        self::assertSame([1], $this->readyOf([
            $this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #50'])),
            $this->row(Issues::pullRequest(50, 'Closes #3'), $this->history('mallory', [])),
            $this->row(Issues::task(3, ['bug'])),
        ]));
    }

    public function testATaskWithAWaitsForLineInAnotherFormIsHeldWithTheLine(): void
    {
        $plan = $this->plan([$this->row(Issues::task(1, ['intakt'], with: ['body' => "Do it.\nWaits for billing#12"]))]);

        self::assertSame([], $this->numbers($plan->ready));
        self::assertSame('unreadable wait: "Waits for billing#12"', $plan->held[0]->reason);
    }

    public function testATaskWaitingInAnOxfordCommaListIsHeldByTheLastOne(): void
    {
        $plan = $this->plan([$this->row(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #9, #10, and #11'])), $this->row(Issues::task(11, ['bug']))]);

        self::assertSame('waits for #11', $plan->held[0]->reason);
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
            $this->row(Issues::pullRequest(50, 'Fixes #1', 'mallory')),
        ]);

        self::assertSame([1 => 51], $plan->pullRequests);
    }

    public function testEveryTrustedOpenPullRequestClosingATaskIsKeptLowestFirst(): void
    {
        $plan = $this->plan([
            $this->row(Issues::task(1)),
            $this->row(Issues::pullRequest(52, 'Fixes #1')),
            $this->row(Issues::pullRequest(51, 'Fixes #1')),
            $this->row(Issues::pullRequest(50, 'Fixes #1', 'mallory')),
        ]);

        self::assertSame([1 => [51, 52]], $plan->closingPullRequests);
    }

    public function testAnEligibilityLabelATriageAccountAppliedMakesNoTask(): void
    {
        self::assertSame([], $this->readyOf([$this->row(Issues::task(1), $this->history('ana', ['intakt' => 'tom']))]));
    }

    public function testATriageAppliedUrgentPriorityIsIgnored(): void
    {
        $ready = $this->readyOf([
            $this->row(Issues::task(1, ['intakt', 'priority:urgent']), $this->history('ana', ['intakt' => 'ana', 'priority:urgent' => 'tom'])),
            $this->row(Issues::task(2, ['intakt', 'priority:high'])),
        ]);

        self::assertSame([2, 1], $ready);
    }

    public function testOfTwoPriorityLabelsTheOneAppliedLastCounts(): void
    {
        $lowLast = $this->row(Issues::task(1, ['intakt', 'priority:low', 'priority:urgent']), $this->history('ana', ['intakt' => 'ana', 'priority:urgent' => 'ana', 'priority:low' => 'ben']));
        $urgentLast = $this->row(Issues::task(2, ['intakt', 'priority:low', 'priority:urgent']), $this->history('ana', ['intakt' => 'ana', 'priority:low' => 'ana', 'priority:urgent' => 'ben']));

        self::assertSame([Priority::Low, Priority::Urgent], [$lowLast->priority(), $urgentLast->priority()]);
        self::assertSame([2, 1], $this->readyOf([$lowLast, $urgentLast]));
    }

    public function testAPriorityLabelAppliedLastByATriageAccountLeavesTheEarlierOneOfAWriterCounting(): void
    {
        self::assertSame(Priority::High, $this->row(Issues::task(1, ['intakt', 'priority:high', 'priority:low']), $this->history('ana', ['intakt' => 'ana', 'priority:high' => 'ana', 'priority:low' => 'tom']))->priority());
    }

    public function testAVouchedLabelTheItemNoLongerCarriesDoesNotCount(): void
    {
        self::assertSame(Priority::Normal, $this->row(Issues::task(1), $this->history('ana', ['intakt' => 'ana', 'priority:urgent' => 'ana']))->priority());
    }

    public function testAnUnknownPriorityValueIsNormal(): void
    {
        self::assertSame(Priority::Normal, Priority::of(['intakt', 'priority:unknown']));
    }

    public function testATaskWhoseChangeIsOpenGoesBeforeAnUrgentTaskNotStartedYet(): void
    {
        $ready = $this->readyOf([
            $this->row(Issues::task(1, ['intakt', 'priority:high'])),
            $this->row(Issues::pullRequest(50, 'Closes #1')),
            $this->row(Issues::task(2, ['intakt', 'priority:urgent'])),
        ]);

        self::assertSame([1, 2], $ready);
    }

    public function testAnUrgentTaskWhoseChangeIsOpenGoesBeforeAnotherTaskWhoseChangeIsOpen(): void
    {
        $ready = $this->readyOf([
            $this->row(Issues::task(1, ['intakt', 'priority:high'])),
            $this->row(Issues::task(2, ['intakt', 'priority:urgent'])),
            $this->row(Issues::pullRequest(50, 'Closes #1')),
            $this->row(Issues::pullRequest(51, 'Closes #2')),
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

    public function testAnOutsidersFixesPullRequestLeavesTheOrderUnchanged(): void
    {
        $ready = $this->readyOf([
            $this->row(Issues::task(1, ['intakt', 'priority:high'])),
            $this->row(Issues::task(2, ['intakt', 'priority:low'])),
            $this->row(Issues::pullRequest(50, 'Fixes #2', 'mallory')),
        ]);

        self::assertSame([1, 2], $ready);
    }

    public function testAPullRequestLastEditedByAnOutsiderLeavesTheOrderUnchanged(): void
    {
        $ready = $this->readyOf([
            $this->row(Issues::task(1, ['intakt', 'priority:high'])),
            $this->row(Issues::task(2, ['intakt', 'priority:low'])),
            $this->row(Issues::pullRequest(50, 'Fixes #2'), $this->history('mallory', [])),
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

    public function testAnotherEligibilityLabelIsTheProjectsOwn(): void
    {
        $plan = new IntakeRules('build')->planOf([$this->row(Issues::task(1, ['intakt'])), $this->row(Issues::task(2, ['build']))], []);

        self::assertSame([2], $this->numbers($plan->ready));
    }

    public function testATaskCarryingOnlyTheLoopsLabelIsNotEligible(): void
    {
        $rules = new IntakeRules('intakt');
        $task = Issues::task(1, ['ready']);

        self::assertSame([], $this->numbers($rules->planOf([$this->row($task)], [])->ready));
        self::assertSame([], $this->numbers($rules->itemsNeedingHistory($this->unread($task))));
    }

    public function testATaskCarryingTheProjectsLabelBesideTheLoopsLabelIsEligible(): void
    {
        $plan = new IntakeRules('intakt')->planOf([$this->row(Issues::task(1, ['ready', 'intakt']))], []);

        self::assertSame([1], $this->numbers($plan->ready));
    }

    public function testAWaitOnAPullRequestWithoutAClosingKeywordHoldsTheTaskWhenOnlyTheHistoryTheRuleAsksForIsRead(): void
    {
        $plan = $this->plan($this->readAsTheRuleAsks(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #50']), Issues::pullRequest(50, 'A refactoring.')));

        self::assertSame([], $this->numbers($plan->ready));
        self::assertSame('waits for #50', $plan->held[0]->reason);
    }

    public function testAnOutsidersPullRequestWithoutAClosingKeywordIsNoWaitWhenOnlyTheHistoryTheRuleAsksForIsRead(): void
    {
        self::assertSame([1], $this->readyOf($this->readAsTheRuleAsks(Issues::task(1, ['intakt'], with: ['body' => 'Waits for #50']), Issues::pullRequest(50, 'A refactoring.', 'mallory'))));
    }

    public function testATaskCandidateAPullRequestClosingATaskAndAPullRequestACandidateWaitsForNeedTheirHistory(): void
    {
        $items = $this->unread(
            Issues::task(1, ['intakt'], with: ['body' => 'Waits for #50, #52']),
            Issues::task(2, ['intakt', 'idea'], with: ['body' => 'Waits for #53']),
            Issues::task(3, ['bug'], with: ['body' => 'Waits for #54']),
            Issues::pullRequest(50, 'A refactoring.'),
            Issues::pullRequest(51, 'Fixes #4'),
            Issues::pullRequest(52, 'A refactoring.', 'mallory'),
            Issues::pullRequest(53, 'A refactoring.'),
            Issues::pullRequest(54, 'A refactoring.'),
            Issues::pullRequest(55, 'A refactoring.'),
        );

        self::assertSame([1, 50, 51, 52], $this->numbers(new IntakeRules('intakt')->itemsNeedingHistory($items)));
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
     * @return list<QueuedIssue>
     */
    private function unread(ListedIssue ...$listed): array
    {
        $references = new IssueReferences();

        return array_values(array_map(static fn (ListedIssue $item): QueuedIssue => new QueuedIssue(new ItemReading($item, $references->waitsFor($item->body), $references->unreadableWaits($item->body), $references->closedBy($item->body))), $listed));
    }

    // Vouches only the items the rule asks for, as the caller will, because a row vouched regardless hides a pull request the rule forgot to ask for.
    /**
     * @return list<QueuedIssue>
     */
    private function readAsTheRuleAsks(ListedIssue ...$listed): array
    {
        $items = $this->unread(...$listed);
        $asked = $this->numbers(new IntakeRules('intakt')->itemsNeedingHistory($items));

        return array_map(fn (QueuedIssue $item, ListedIssue $source): QueuedIssue => in_array($item->number(), $asked, true) ? $this->vouched($item, $source) : $item, $items, $listed);
    }

    private function row(ListedIssue $listed, ?IssueHistory $history = null): QueuedIssue
    {
        return $this->vouched($this->unread($listed)[0], $listed, $history);
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

    // Without a history the author last edited the item and applied every label, because most cases are about something else.
    private function vouched(QueuedIssue $row, ListedIssue $listed, ?IssueHistory $history = null): QueuedIssue
    {
        return $row->vouchedBy(Vouching::of($listed->author, $history ?? $this->history($listed->author, array_fill_keys([...$listed->labels], $listed->author)), static fn (string $account): bool => in_array($account, self::WRITERS, true)));
    }

    // Keyed by label, earliest application first, because a label applied again takes its later place.

    /**
     * @param array<string, string> $applications
     */
    private function history(string $lastEditor, array $applications): IssueHistory
    {
        return new IssueHistory($lastEditor, array_map(static fn (string $label, string $account): LabelApplication => new LabelApplication($label, $account), array_keys($applications), array_values($applications)), '', new IssueLabels(...array_keys($applications)));
    }
}
