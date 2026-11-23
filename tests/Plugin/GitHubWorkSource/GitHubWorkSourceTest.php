<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Closure;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\BranchPullRequest;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueHistories;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueReads;
use Intakt\Plugin\GitHubWorkSource\Domain\HeldIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemNotAsPlanned;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\ListingRows;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\SyncState;
use Intakt\Plugin\GitHubWorkSource\Domain\TaskBranchName;
use Intakt\Plugin\GitHubWorkSource\Domain\TaskFailure;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\CompleteListing;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\GitHubWorkSource;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\IntakeStore;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\PullRequestsOfTasks;
use Intakt\Plugin\WorkSource\Contract\HeldTask;
use Intakt\Plugin\WorkSource\Contract\NothingPicked;
use Intakt\Plugin\WorkSource\Contract\Task;
use Intakt\Plugin\WorkSource\Contract\TaskNotQueued;
use Intakt\Plugin\WorkSource\Contract\WorkerState;
use Intakt\Plugin\WorkSource\Contract\WorkPick;
use Intakt\Plugin\WorkSource\Contract\WorkQueue;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Plugin\GitHubForge\RecordedIssueApi;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\ErrorHandler\BufferingLogger;

#[Group('persistence')]
#[CoversClass(GitHubWorkSource::class)]
#[CoversClass(PullRequestsOfTasks::class)]
#[UsesClass(TaskBranchName::class)]
#[UsesClass(BranchPullRequest::class)]
#[UsesClass(CompleteListing::class)]
#[UsesClass(EligibilityLabels::class)]
#[UsesClass(IntakeStore::class)]
#[UsesClass(StoredIssue::class)]
#[UsesClass(TaskFailure::class)]
#[UsesClass(IntakeRules::class)]
#[UsesClass(IntakePlan::class)]
#[UsesClass(HeldIssue::class)]
#[UsesClass(IssueReferences::class)]
#[UsesClass(ItemReading::class)]
#[UsesClass(ItemNotAsPlanned::class)]
#[UsesClass(IntakeValidator::class)]
#[UsesClass(ListingRows::class)]
#[UsesClass(QueuedIssue::class)]
#[UsesClass(SyncState::class)]
#[UsesClass(Vouching::class)]
#[UsesClass(IssueListing::class)]
#[UsesClass(GitHubIssueReads::class)]
#[UsesClass(GitHubIssueHistories::class)]
#[UsesClass(WorkPick::class)]
#[UsesClass(WorkQueue::class)]
#[UsesClass(Task::class)]
#[UsesClass(HeldTask::class)]
final class GitHubWorkSourceTest extends KernelTestCase
{
    private IntakeStore $store;
    private RecordedIssueReads $reads;
    private RecordedPullRequestBranches $branches;
    private BufferingLogger $logger;
    private string $editor = 'ana';
    /** @var array<int, string> */
    private array $editorOf = [];
    /** @var list<string> */
    private array $writers = ['ana', 'bea'];
    private string $labelApplier = 'ana';
    private string $eligibilityLabel = 'intakt';
    private ?ForgeReadFailed $historyFailure = null;
    private ?int $historyFailureOf = null;
    /** @var array<int, array<string, string>> */
    private array $appliersOf = [];
    /** @var ?list<string> */
    private ?array $appliedInOrder = null;
    /** @var array<int, string> */
    private array $editedBodies = [];
    /** @var list<string> */
    private array $asked = [];
    /** @var list<int> */
    private array $historiesRead = [];
    private ?Closure $duringHistory = null;
    private ?TrustNotChecked $accessFailure = null;

    public function testAWorkerThatWillNotTakeWorkMakesNoForgeCall(): void
    {
        $this->reads->lists([Issues::task(1)]);

        foreach ([WorkerState::NoFreeSlot, WorkerState::Paused, WorkerState::Draining, WorkerState::HeldByBudget] as $state) {
            $pick = $this->source()->nextWork('alpha', $state);

            self::assertSame(NothingPicked::WorkerNotTakingWork, $pick->nothing);
        }

        self::assertSame(0, $this->reads->listings);
        self::assertSame([], $this->historiesRead);
        self::assertSame([], $this->asked);
    }

    public function testTheTaskThatSortsFirstIsPickedAndIsAReworkWhenATrustedPullRequestClosesIt(): void
    {
        $this->reads->lists([Issues::task(7), Issues::task(8), Issues::pullRequest(12, 'Closes #8')]);

        $pick = $this->source()->nextWork('alpha', WorkerState::Free);

        self::assertEquals(new Task(8, 'Task 8', 12), $pick->task);
    }

    public function testATaskWhosePullRequestIsFoundOnlyByItsBranchIsPickedAsAReworkOfIt(): void
    {
        $this->branches->branchOf = [12 => 'feat/7-add-intake'];
        $this->reads->lists([Issues::task(7), Issues::task(8), Issues::pullRequest(12, 'No closing keyword here.')]);

        $pick = $this->source()->nextWork('alpha', WorkerState::Free);

        self::assertEquals(new Task(7, 'Task 7', 12), $pick->task);
        self::assertSame(1, $this->branches->reads);
    }

    public function testATaskWhosePullRequestIsFoundOnlyByItsBranchIsQueuedAsAReworkOfIt(): void
    {
        $this->branches->branchOf = [12 => 'feat/8-add-intake'];
        $this->reads->lists([Issues::task(7), Issues::task(8, ['intakt'], ['body' => 'Waits for #7']), Issues::pullRequest(12, '')]);

        $queue = $this->source()->queue('alpha');

        self::assertEquals([new Task(7, 'Task 7', null)], $queue->ready());
        self::assertEquals([new HeldTask(new Task(8, 'Task 8', 12), 'waits for #7')], $queue->held());
    }

    public function testATaskQueuedAgainAfterItsStoredRowIsRemovedIsQueuedAsAReworkOfItsPullRequestFoundByBranch(): void
    {
        $this->branches->branchOf = [12 => 'feat/7-add-intake'];
        $this->reads->lists([Issues::task(7), Issues::pullRequest(12, '')]);
        $source = $this->source();
        $source->queue('alpha');
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $entities->remove($entities->find(StoredIssue::class, ['project' => 'alpha', 'number' => 7]) ?? self::fail('Task 7 was not stored.'));
        $entities->flush();
        $entities->clear();
        self::assertNull($entities->find(StoredIssue::class, ['project' => 'alpha', 'number' => 7]));
        $this->reads->lists([Issues::task(7), Issues::pullRequest(12, '')], 'W/"issues-2"');

        $queue = $source->queue('alpha');

        self::assertEquals([new Task(7, 'Task 7', 12)], $queue->ready());
        self::assertSame(2, $this->branches->reads);
    }

    public function testTheBranchesAreReadOnEveryQueueAndNeverRemembered(): void
    {
        $this->branches->branchOf = [12 => 'feat/7-add-intake'];
        $this->reads->lists([Issues::task(7), Issues::pullRequest(12, '')]);
        $source = $this->source();
        self::assertSame(12, $source->queue('alpha')->ready()[0]->reworkOf);
        $this->branches->branchOf = [];
        $this->reads->listsUnchanged();

        self::assertNull($source->queue('alpha')->ready()[0]->reworkOf);
        self::assertSame(2, $this->branches->reads);
    }

    public function testThePullRequestOfTheTaskIsTheLowestFoundByClosingKeywordOrByBranch(): void
    {
        $this->branches->branchOf = [10 => 'feat/7-first-try', 15 => 'feat/8-later-try'];
        $this->reads->lists([Issues::task(7), Issues::task(8), Issues::pullRequest(10, ''), Issues::pullRequest(12, 'Closes #7, closes #8'), Issues::pullRequest(15, '')]);

        $queue = $this->source()->queue('alpha');

        self::assertEquals([new Task(7, 'Task 7', 10), new Task(8, 'Task 8', 12)], $queue->ready());
    }

    public function testAPullRequestOnABranchThatNamesAnotherTaskDoesNotMakeTheTaskARework(): void
    {
        $this->branches->branchOf = [12 => 'feat/70-add-intake'];
        $this->reads->lists([Issues::task(7), Issues::pullRequest(12, '')]);

        self::assertNull($this->source()->nextWork('alpha', WorkerState::Free)->task?->reworkOf);
    }

    public function testAPullRequestOnTheTaskBranchWhoseAuthorHasNoWriteAccessDoesNotMakeTheTaskARework(): void
    {
        $this->branches->branchOf = [11 => 'feat/7-from-a-stranger', 12 => 'feat/7-add-intake'];
        $this->reads->lists([Issues::task(7), Issues::pullRequest(11, '', 'mallory'), Issues::pullRequest(12, '')]);

        self::assertEquals(new Task(7, 'Task 7', 12), $this->source()->nextWork('alpha', WorkerState::Free)->task);
        self::assertNotContains(11, $this->historiesRead);
    }

    public function testAPullRequestOnTheTaskBranchLastEditedByAnAccountWithoutWriteAccessDoesNotMakeTheTaskARework(): void
    {
        $this->editorOf = [12 => 'mallory'];
        $this->branches->branchOf = [12 => 'feat/7-add-intake'];
        $this->reads->lists([Issues::task(7), Issues::pullRequest(12, '')]);

        self::assertEquals(new Task(7, 'Task 7', null), $this->source()->nextWork('alpha', WorkerState::Free)->task);
    }

    public function testAPullRequestClosingTheTaskWhoseAuthorLostWriteAccessSinceTheListingDoesNotMakeTheTaskARework(): void
    {
        $this->reads->lists([Issues::task(7), Issues::pullRequest(12, 'Closes #7', 'bea')]);
        $source = $this->source();
        self::assertSame(12, $source->queue('alpha')->ready()[0]->reworkOf);
        $this->writers = ['ana'];
        $this->reads->listsUnchanged();

        self::assertNull($source->queue('alpha')->ready()[0]->reworkOf);
    }

    public function testASecondTrustedPullRequestClosingTheTaskMakesItAReworkWhenTheFirstLostWriteAccessSinceTheListing(): void
    {
        $this->reads->lists([Issues::task(7), Issues::pullRequest(12, 'Closes #7', 'bea'), Issues::pullRequest(13, 'Closes #7', 'ana')]);
        $source = $this->source();
        self::assertSame(12, $source->queue('alpha')->ready()[0]->reworkOf);
        $this->writers = ['ana'];
        $this->reads->listsUnchanged();

        self::assertSame(13, $source->queue('alpha')->ready()[0]->reworkOf);
    }

    public function testAPullRequestEditedToCloseAnotherTaskWhileTheBranchesAreReadPicksNothingThisTick(): void
    {
        $this->reads->lists([Issues::task(7), Issues::pullRequest(12, 'Closes #7', 'bea')]);
        $this->branches->during = function (): void {
            $this->editedBodies[12] = 'Closes #8';
        };

        $pick = $this->source()->nextWork('alpha', WorkerState::Free);

        self::assertSame(NothingPicked::ListingIncomplete, $pick->nothing);
        self::assertSame('GitHub holds pull request #12 without the closing reference to #7 it was listed with, so the pull request of that task is not known until the next listing.', $this->onlyLog()['context']['reason'] ?? null);
    }

    public function testAPullRequestOnTheTaskBranchEditedToCloseAnotherTaskStillMakesTheTaskARework(): void
    {
        $this->branches->branchOf = [12 => 'fix/7-intake'];
        $this->reads->lists([Issues::task(7), Issues::pullRequest(12, 'Closes #7', 'bea')]);
        $this->branches->during = function (): void {
            $this->editedBodies[12] = 'Closes #8';
        };

        self::assertEquals(new Task(7, 'Task 7', 12), $this->source()->nextWork('alpha', WorkerState::Free)->task);
    }

    public function testAPullRequestEditedToCloseAnotherTaskByAnAccountWithoutWriteAccessCountsForNothing(): void
    {
        $this->reads->lists([Issues::task(7), Issues::pullRequest(12, 'Closes #7', 'bea')]);
        $this->branches->during = function (): void {
            $this->editedBodies[12] = 'Closes #8';
            $this->editorOf[12] = 'mallory';
        };

        self::assertEquals(new Task(7, 'Task 7', null), $this->source()->nextWork('alpha', WorkerState::Free)->task);
    }

    public function testATaskMarkedFailedWhileItsBranchesAreReadIsNeverPicked(): void
    {
        $this->reads->lists([Issues::task(7)]);
        $source = $this->source();
        $this->branches->during = static fn () => $source->failedForGood('alpha', 7, 'failed during the branch read');

        $pick = $source->nextWork('alpha', WorkerState::Free);

        self::assertSame(NothingPicked::NoTaskReady, $pick->nothing);
    }

    public function testABranchReadThatFailsPicksNothingThisTick(): void
    {
        $this->branches->failure = new ForgeReadFailed('GitHub answered 502 to a read.');
        $this->reads->lists([Issues::task(7)]);

        $pick = $this->source()->nextWork('alpha', WorkerState::Free);

        self::assertSame(NothingPicked::ListingIncomplete, $pick->nothing);
        $log = $this->onlyLog();
        self::assertSame('warning', $log['level']);
        self::assertSame('GitHub answered 502 to a read.', $log['context']['reason'] ?? null);
    }

    public function testABranchReadThatFailsShowsNoQueue(): void
    {
        $this->branches->failure = new ForgeReadFailed('GitHub answered 502 to a read.');
        $this->reads->lists([Issues::task(7)]);

        $queue = $this->source()->queue('alpha');

        self::assertFalse($queue->complete);
        self::assertSame('GitHub answered 502 to a read.', $this->onlyLog()['context']['reason'] ?? null);
    }

    public function testAPullRequestTheListingShowsOpenButTheBranchReadLeavesOutShowsNoQueue(): void
    {
        $this->branches->leftOut = [12];
        $this->reads->lists([Issues::task(7), Issues::pullRequest(12, '')]);

        $queue = $this->source()->queue('alpha');

        self::assertFalse($queue->complete);
        self::assertSame('GitHub did not list the branch of open pull request #12, which the listing of this tick shows open, so the pull requests by branch may be incomplete.', $this->onlyLog()['context']['reason'] ?? null);
    }

    public function testAHistoryOfThePullRequestOnTheTaskBranchThatFailsPicksNothingThisTick(): void
    {
        $this->branches->branchOf = [12 => 'feat/7-add-intake'];
        $this->reads->lists([Issues::task(7), Issues::pullRequest(12, '')]);
        $source = $this->source();
        $source->queue('alpha');
        $this->reads->listsUnchanged();
        $this->historyFailure = new ForgeReadFailed('GitHub updated #12 after it was listed.');
        $this->historyFailureOf = 12;
        $this->historiesRead = [];

        $pick = $source->nextWork('alpha', WorkerState::Free);

        self::assertSame(NothingPicked::ListingIncomplete, $pick->nothing);
        self::assertSame([7, 12], $this->historiesRead);
        self::assertSame('GitHub updated #12 after it was listed.', $this->onlyLog()['context']['reason'] ?? null);
    }

    public function testAnIssueEditedByAnUntrustedAccountIsNotPickedUntilATrustedEditFollows(): void
    {
        $this->editor = 'mallory';
        $this->reads->lists([Issues::task(5)]);
        $source = $this->source();

        self::assertSame(NothingPicked::NoTaskReady, $source->nextWork('alpha', WorkerState::Free)->nothing);

        $this->editor = 'ana';
        $this->reads->lists([Issues::task(5, ['intakt'], ['updatedAt' => '2026-11-10T11:00:00Z'])], 'W/"issues-2"');

        self::assertSame(5, $source->nextWork('alpha', WorkerState::Free)->task?->number);
    }

    public function testWriteAccessOfTheAuthorAndTheLastEditorIsAskedAgainBeforeAPick(): void
    {
        $this->reads->lists([Issues::task(5, ['intakt'], ['author' => 'bea'])]);
        $source = $this->source();
        self::assertSame(5, $source->queue('alpha')->ready()[0]->number);
        $this->asked = [];
        $this->reads->listsUnchanged();

        self::assertSame(5, $source->nextWork('alpha', WorkerState::Free)->task?->number);
        self::assertSame(['bea', 'ana'], $this->asked);
    }

    public function testATaskWhoseLastEditorLostWriteAccessSinceItWasListedIsNotPicked(): void
    {
        $this->reads->lists([Issues::task(5, ['intakt'], ['author' => 'bea'])]);
        $source = $this->source();
        $source->queue('alpha');
        $this->writers = ['bea'];
        $this->reads->listsUnchanged();

        self::assertSame(NothingPicked::NoTaskReady, $source->nextWork('alpha', WorkerState::Free)->nothing);
    }

    public function testATaskWhoseAuthorLostWriteAccessSinceItWasListedIsNotPicked(): void
    {
        $this->reads->lists([Issues::task(5, ['intakt'], ['author' => 'bea'])]);
        $source = $this->source();
        $source->queue('alpha');
        $this->writers = ['ana'];
        $this->reads->listsUnchanged();

        self::assertSame(NothingPicked::NoTaskReady, $source->nextWork('alpha', WorkerState::Free)->nothing);
    }

    public function testAnIdeaLabelKeepsATaskWithTheEligibilityLabelFromBeingPicked(): void
    {
        $this->reads->lists([Issues::task(5, ['intakt', 'idea']), Issues::task(6)]);

        self::assertSame(6, $this->source()->nextWork('alpha', WorkerState::Free)->task?->number);
    }

    public function testAnEligibilityLabelAppliedByAnAccountWithoutWriteAccessIsNotPicked(): void
    {
        $this->labelApplier = 'mallory';
        $this->reads->lists([Issues::task(5)]);

        self::assertSame(NothingPicked::NoTaskReady, $this->source()->nextWork('alpha', WorkerState::Free)->nothing);
    }

    public function testAWaitAddedInTheSecondOfTheListingAfterThePlanKeepsTheTaskFromBeingPicked(): void
    {
        $this->reads->lists([Issues::task(5), Issues::task(6)]);
        $source = $this->source();
        self::assertSame(5, $source->queue('alpha')->ready()[0]->number);
        $this->reads->listsUnchanged();
        $this->editedBodies = [5 => 'Waits for #6'];

        $pick = $source->nextWork('alpha', WorkerState::Free);

        self::assertSame(NothingPicked::TrustNotChecked, $pick->nothing);
        self::assertSame('GitHub holds #5 with other labels, waits or closing references than it was planned with, so its history is not of the planned item.', $this->onlyLog()['context']['reason'] ?? null);
    }

    public function testATaskWhoseWaitChangedSinceThePlanIsReadAgainSoTheNextListingPicksTheTaskBehindIt(): void
    {
        $this->reads->lists([Issues::task(5), Issues::task(6)]);
        $source = $this->source();
        $source->queue('alpha');
        $this->reads->listsUnchanged();
        $this->editedBodies = [5 => 'Waits for #6'];
        self::assertSame(NothingPicked::TrustNotChecked, $source->nextWork('alpha', WorkerState::Free)->nothing);
        $this->logger->cleanLogs();
        $this->reads->lists([Issues::task(5, ['intakt'], ['body' => 'Waits for #6']), Issues::task(6)]);

        $pick = $source->nextWork('alpha', WorkerState::Free);

        self::assertSame(6, $pick->task?->number);
        self::assertSame([null, 'W/"issues-1"', null], $this->reads->validators);
    }

    public function testATaskWhoseLabelANonWriterPutBackIsHeldOnTheNextListingAndTheTaskBehindItIsPicked(): void
    {
        $this->reads->lists([Issues::task(5), Issues::task(6)]);
        $source = $this->source();
        $source->queue('alpha');
        $this->reads->listsUnchanged();
        $this->appliersOf = [5 => ['intakt' => 'triager']];
        self::assertSame(NothingPicked::TrustNotChecked, $source->nextWork('alpha', WorkerState::Free)->nothing);
        $this->logger->cleanLogs();
        $this->historiesRead = [];
        $this->reads->lists([Issues::task(5), Issues::task(6)]);

        $pick = $source->nextWork('alpha', WorkerState::Free);

        self::assertSame(6, $pick->task?->number);
        self::assertSame([5, 6], $this->historiesRead);
        self::assertSame([null, 'W/"issues-1"', null], $this->reads->validators);
    }

    public function testAWriterHoldingTheDatabaseWhenTheTickForgetsAChangedTaskIsLoggedAndPicksNothing(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'intake');
        $writer = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        try {
            $this->store = $this->storeOnAFileThatRefusesBusyReads($file);
            $this->reads->lists([Issues::task(5), Issues::task(6)]);
            $source = $this->source();
            $source->queue('alpha');
            $this->reads->listsUnchanged();
            $this->appliersOf = [5 => ['intakt' => 'triager']];
            $this->duringHistory = static fn () => $writer->executeStatement('BEGIN EXCLUSIVE');

            $pick = $source->nextWork('alpha', WorkerState::Free);

            self::assertSame(NothingPicked::TrustNotChecked, $pick->nothing);
            self::assertSame([
                'The labels of #5 are no longer applied by the accounts with write access the plan counted, so it is not the planned item.',
                'Another writer holds the intake rows of project "alpha" and has not committed them.',
            ], $this->reasonsLogged());
        } finally {
            $writer->close();
            unlink($file);
        }
    }

    public function testAHistoryReadThatFailsAtThePickForgetsNothing(): void
    {
        $this->reads->lists([Issues::task(5), Issues::task(6)]);
        $source = $this->source();
        $source->queue('alpha');
        $this->historyFailure = new ForgeReadFailed('GitHub answered 502 to a read.');
        $this->reads->listsUnchanged();

        $pick = $source->nextWork('alpha', WorkerState::Free);

        $this->logger->cleanLogs();
        self::assertSame(NothingPicked::TrustNotChecked, $pick->nothing);
        self::assertSame('W/"issues-1"', $this->store->syncStateOf('alpha')->validator);
        self::assertTrue(array_first($this->store->issuesOf('alpha'))?->isVouched());
    }

    public function testATextRewordedWithoutAWaitOrClosingReferenceStillPicksTheTask(): void
    {
        $this->reads->lists([Issues::task(5), Issues::task(6)]);
        $source = $this->source();
        $source->queue('alpha');
        $this->reads->listsUnchanged();
        $this->editedBodies = [5 => 'The same task, said in other words.'];

        self::assertSame(5, $source->nextWork('alpha', WorkerState::Free)->task?->number);
    }

    public function testAnEligibilityLabelAppliedAgainWithoutWriteAccessInTheSecondOfTheListingPicksNothing(): void
    {
        $this->reads->lists([Issues::task(5), Issues::task(6)]);
        $source = $this->source();
        self::assertSame(5, $source->queue('alpha')->ready()[0]->number);
        $this->reads->listsUnchanged();
        $this->appliersOf = [5 => ['intakt' => 'triager']];

        $pick = $source->nextWork('alpha', WorkerState::Free);

        self::assertNull($pick->task);
        self::assertSame(NothingPicked::TrustNotChecked, $pick->nothing);
        self::assertSame('The labels of #5 are no longer applied by the accounts with write access the plan counted, so it is not the planned item.', $this->onlyLog()['context']['reason'] ?? null);
    }

    public function testAPriorityLabelAppliedAgainWithoutWriteAccessInTheSecondOfTheListingPicksNothing(): void
    {
        $this->reads->lists([Issues::task(5, ['intakt', 'priority:urgent']), Issues::task(6)]);
        $source = $this->source();
        self::assertSame(5, $source->queue('alpha')->ready()[0]->number);
        $this->reads->listsUnchanged();
        $this->appliersOf = [5 => ['priority:urgent' => 'triager']];

        $pick = $source->nextWork('alpha', WorkerState::Free);

        self::assertSame(NothingPicked::TrustNotChecked, $pick->nothing);
        self::assertSame('The labels of #5 are no longer applied by the accounts with write access the plan counted, so it is not the planned item.', $this->onlyLog()['context']['reason'] ?? null);
    }

    public function testTwoNonPriorityLabelsAppliedAgainInTheOtherOrderByAWriterStillPickTheTask(): void
    {
        $this->reads->lists([Issues::task(5, ['intakt', 'docs'])]);
        $source = $this->source();
        $source->queue('alpha');
        $this->reads->listsUnchanged();
        $this->appliedInOrder = ['docs', 'intakt'];

        self::assertSame(5, $source->nextWork('alpha', WorkerState::Free)->task?->number);
    }

    public function testPriorityLabelsAppliedAgainInTheOtherOrderByAWriterPickNothing(): void
    {
        $this->reads->lists([Issues::task(5, ['intakt', 'priority:low', 'priority:high']), Issues::task(6)]);
        $source = $this->source();
        self::assertSame(5, $source->queue('alpha')->ready()[0]->number);
        $this->reads->listsUnchanged();
        $this->appliedInOrder = ['intakt', 'priority:high', 'priority:low'];

        self::assertSame(NothingPicked::TrustNotChecked, $source->nextWork('alpha', WorkerState::Free)->nothing);
        self::assertSame('The labels of #5 are no longer applied by the accounts with write access the plan counted, so it is not the planned item.', $this->onlyLog()['context']['reason'] ?? null);
    }

    public function testALabelAppliedWithoutWriteAccessSinceBeforeTheListingDoesNotKeepTheTaskFromBeingPicked(): void
    {
        $this->appliersOf = [5 => ['docs' => 'triager']];
        $this->reads->lists([Issues::task(5, ['intakt', 'docs'])]);
        $source = $this->source();
        $source->queue('alpha');
        $this->reads->listsUnchanged();

        self::assertSame(5, $source->nextWork('alpha', WorkerState::Free)->task?->number);
    }

    public function testAWriterHoldingTheDatabaseWhenThePickReadsTheFailuresPicksNothingAndLogsTheReason(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'intake');
        $writer = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        try {
            $this->store = $this->storeOnAFileThatRefusesBusyReads($file);
            $this->reads->lists([Issues::task(5)]);
            $source = $this->source();
            $source->queue('alpha');
            $this->reads->listsUnchanged();
            $this->duringHistory = static fn () => $writer->executeStatement('BEGIN EXCLUSIVE');

            $pick = $source->nextWork('alpha', WorkerState::Free);

            self::assertSame(NothingPicked::ListingIncomplete, $pick->nothing);
            self::assertSame('Another writer holds the intake rows of project "alpha" and has not committed them.', $this->onlyLog()['context']['reason'] ?? null);
        } finally {
            $writer->close();
            unlink($file);
        }
    }

    public function testATrustCheckThatFailsIsLoggedAsAFailedCheckAndPicksNothing(): void
    {
        $this->reads->lists([Issues::task(5), Issues::task(6)]);
        $source = $this->source();
        $source->queue('alpha');
        $this->accessFailure = TrustNotChecked::becauseGitHubRefused(502);
        $this->reads->listsUnchanged();

        $pick = $source->nextWork('alpha', WorkerState::Free);

        self::assertSame(NothingPicked::TrustNotChecked, $pick->nothing);
        $log = $this->onlyLog();
        self::assertSame('warning', $log['level']);
        self::assertStringContainsString('write-access check of task #{number}', $log['message']);
        self::assertSame(5, $log['context']['number'] ?? null);
        self::assertSame('GitHub refused the trust check with status 502.', $log['context']['reason'] ?? null);
    }

    public function testAHistoryReadThatFailsAtThePickIsLoggedAsAFailedCheckAndPicksNothing(): void
    {
        $this->reads->lists([Issues::task(5), Issues::task(6)]);
        $source = $this->source();
        $source->queue('alpha');
        $this->historyFailure = new ForgeReadFailed('GitHub answered 502 to a read.');
        $this->reads->listsUnchanged();

        $pick = $source->nextWork('alpha', WorkerState::Free);

        self::assertSame(NothingPicked::TrustNotChecked, $pick->nothing);
        self::assertSame('GitHub answered 502 to a read.', $this->onlyLog()['context']['reason'] ?? null);
    }

    public function testATaskThatWaitsForAnOpenIssueIsHeldAndTheIssueItWaitsForIsPicked(): void
    {
        $this->reads->lists([Issues::task(3), Issues::task(10, ['intakt'], ['body' => 'Waits for #3'])]);
        $source = $this->source();

        $queue = $source->queue('alpha');

        self::assertSame([3], array_map(static fn (Task $task): int => $task->number, $queue->ready()));
        self::assertSame([[10, 'waits for #3']], array_map(static fn (HeldTask $held): array => [$held->task->number, $held->reason], $queue->held()));
        self::assertSame(3, $source->nextWork('alpha', WorkerState::Free)->task?->number);
    }

    public function testATaskCarryingOnlyTheLoopsLabelIsNeitherQueuedNorPicked(): void
    {
        $this->reads->lists([Issues::task(7, ['ready'])]);
        $source = $this->source();

        $queue = $source->queue('alpha');

        self::assertSame([[], []], [$queue->ready(), $queue->held()]);
        $this->reads->listsUnchanged();
        self::assertSame(NothingPicked::NoTaskReady, $source->nextWork('alpha', WorkerState::Free)->nothing);
    }

    public function testATaskCarryingTheLabelTheProjectNamesIsQueuedAndPicked(): void
    {
        $this->eligibilityLabel = 'build-me';
        $this->reads->lists([Issues::task(7, ['ready']), Issues::task(8, ['build-me'])]);
        $source = $this->source();

        $queue = $source->queue('alpha');

        self::assertSame([8], array_map(static fn (Task $task): int => $task->number, $queue->ready()));
        $this->reads->listsUnchanged();
        self::assertSame(8, $source->nextWork('alpha', WorkerState::Free)->task?->number);
    }

    public function testTheDependentOfATaskFailedForGoodIsHeldAndNotPicked(): void
    {
        $this->reads->lists([Issues::task(3), Issues::task(10, ['intakt'], ['body' => 'Waits for #3'])]);
        $source = $this->source();
        $source->queue('alpha');

        $source->failedForGood('alpha', 3, 'the build broke');
        $this->reads->listsUnchanged();
        $queue = $source->queue('alpha');

        self::assertSame([], $queue->ready());
        self::assertSame(['failed for good: the build broke', 'depends on #3, which failed for good: the build broke'], array_map(static fn (HeldTask $held): string => $held->reason, $queue->held()));
        $this->reads->listsUnchanged();
        self::assertSame(NothingPicked::NoTaskReady, $source->nextWork('alpha', WorkerState::Free)->nothing);
    }

    public function testATaskMarkedFailedBetweenTheListingAndThePickIsNeverPicked(): void
    {
        $this->reads->lists([Issues::task(5), Issues::task(6)]);
        $source = $this->source();
        $source->queue('alpha');
        $this->reads->listsUnchanged();
        $this->duringHistory = static fn () => $source->failedForGood('alpha', 5, 'failed meanwhile');

        $pick = $source->nextWork('alpha', WorkerState::Free);

        self::assertSame(6, $pick->task?->number);
        self::assertSame(['failed meanwhile'], array_values($this->store->failuresOf('alpha')));
    }

    public function testAFailureAnotherConnectionTriesToRecordWhileThePickReadsTheFailuresIsRefusedInsteadOfMissed(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'intake');
        $events = new EventManager();
        try {
            $writer = $this->storeOnAFileThatRefusesBusyReads($file);
            $this->store = $this->storeOnAFileInWalMode($file, $events);
            $this->reads->lists([Issues::task(5)]);
            $source = $this->source();
            $source->queue('alpha');
            $this->reads->listsUnchanged();
            $refused = null;
            $commit = static function () use ($writer, &$refused): void {
                try {
                    $writer->failForGood('alpha', 5, 'failed during the pick');
                    $refused = false;
                } catch (RetryableException) {
                    $refused = true;
                }
            };
            $this->duringHistory = static fn () => $events->addEventListener(Events::postLoad, new CommitOnValidatorLoad($commit));

            $pick = $source->nextWork('alpha', WorkerState::Free);

            self::assertTrue($refused);
            self::assertSame(5, $pick->task?->number);
            self::assertSame([], $this->store->failuresOf('alpha'));
        } finally {
            unlink($file);
        }
    }

    public function testATaskWhoseWaitedForPullRequestClosesATaskMarkedFailedBetweenTheListingAndThePickIsNeverPicked(): void
    {
        $task = Issues::task(5, ['intakt'], ['body' => 'Waits for #12']);
        $pullRequest = Issues::pullRequest(12, 'Closes #9');
        $this->reads->lists([$task, $pullRequest]);
        $source = $this->source();
        self::assertSame([5], array_map(static fn (Task $ready): int => $ready->number, $source->queue('alpha')->ready()));
        $this->reads->listsUnchanged();
        $this->duringHistory = function () use ($source, $task, $pullRequest): void {
            $this->reads->lists([$task, $pullRequest, Issues::task(9)], 'W/"issues-2"');
            $source->queue('alpha');
            $source->failedForGood('alpha', 9, 'failed meanwhile');
        };

        $pick = $source->nextWork('alpha', WorkerState::Free);

        self::assertSame(NothingPicked::NoTaskReady, $pick->nothing);
    }

    public function testMoreReadyIssuesThanOnePageHoldsPickTheIssueThatSortsFirstOnTheLastPage(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(2, 100), 2 => $this->page(1, 1)];
        $api->namingNext = [1];
        $api->stable = true;
        $api->graphql = $this->historyAnswer();

        $pick = $this->sourceOver($api->reads(), $api->histories())->nextWork('alpha', WorkerState::Free);

        self::assertSame(1, $pick->task?->number);
    }

    public function testALaterPageThatFailsPicksNothingAndLogsTheReason(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(2, 100), 2 => '502'];
        $api->namingNext = [1];
        $api->graphql = $this->historyAnswer();

        $pick = $this->sourceOver($api->reads(), $api->histories())->nextWork('alpha', WorkerState::Free);

        self::assertSame(NothingPicked::ListingIncomplete, $pick->nothing);
        self::assertSame('GitHub answered 502 to a read.', $this->onlyLog()['context']['reason'] ?? null);
    }

    public function testAQueueOverAListingThatCameBackShortIsIncomplete(): void
    {
        self::assertFalse($this->source()->queue('alpha')->complete);
    }

    public function testATaskThatIsNotInTheListingCannotBeMarkedFailed(): void
    {
        $this->expectException(TaskNotQueued::class);

        $this->source()->failedForGood('alpha', 99, 'no such task');
    }

    public function testAPullRequestInTheListingCannotBeMarkedFailed(): void
    {
        $this->reads->lists([Issues::task(1), Issues::pullRequest(12, 'Closes #1')]);
        $source = $this->source();
        $source->queue('alpha');

        $refused = null;
        try {
            $source->failedForGood('alpha', 12, 'not a task');
        } catch (TaskNotQueued $refused) {
        }

        self::assertInstanceOf(TaskNotQueued::class, $refused);
        self::assertSame([], $this->store->failuresOf('alpha'));
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->store = new IntakeStore($entities);
        $this->reads = new RecordedIssueReads();
        $this->branches = new RecordedPullRequestBranches($this->reads);
        $this->logger = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
        parent::tearDown();
    }

    private function source(): GitHubWorkSource
    {
        $histories = self::createStub(IssueHistories::class);
        $histories->method('of')->willReturnCallback(function (string $project, int $number): IssueHistory {
            $this->historiesRead[] = $number;
            $during = $this->duringHistory;
            $this->duringHistory = null;
            if ($during instanceof Closure) {
                $during();
            }

            if ($this->historyFailure instanceof ForgeReadFailed && in_array($this->historyFailureOf, [null, $number], true)) {
                throw $this->historyFailure;
            }

            $listed = $this->reads->lastListed[$number];

            return new IssueHistory($this->editorOf[$number] ?? $this->editor, array_map(fn (string $label): LabelApplication => new LabelApplication($label, $this->appliersOf[$number][$label] ?? $this->labelApplier), $this->appliedInOrder ?? $listed->labels), $this->editedBodies[$number] ?? $listed->body, $listed->labels);
        });

        return $this->sourceOver($this->reads, $histories);
    }

    private function storeOnAFileThatRefusesBusyReads(string $file): IntakeStore
    {
        $shared = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $shared);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        $connection->executeStatement('PRAGMA busy_timeout = 0');
        $manager = new EntityManager($connection, $shared->getConfiguration(), new EventManager());
        new SchemaTool($manager)->createSchema($manager->getMetadataFactory()->getAllMetadata());

        return new IntakeStore($manager);
    }

    /**
     * @return array{level: string, message: string, context: array<mixed>}
     */
    private function onlyLog(): array
    {
        $logs = $this->logger->cleanLogs();
        self::assertCount(1, $logs);
        $record = $logs[0];
        self::assertIsArray($record);
        [$level, $message, $context] = $record;
        self::assertIsString($level);
        self::assertIsString($message);
        self::assertIsArray($context);

        return ['level' => $level, 'message' => $message, 'context' => $context];
    }

    /**
     * @return list<mixed>
     */
    private function reasonsLogged(): array
    {
        return array_values(array_map(static fn (mixed $record): mixed => is_array($record) && is_array($record[2] ?? null) ? $record[2]['reason'] ?? null : null, $this->logger->cleanLogs()));
    }

    private function storeOnAFileInWalMode(string $file, EventManager $events): IntakeStore
    {
        $shared = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $shared);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        $connection->executeQuery('PRAGMA journal_mode = WAL')->fetchOne();

        return new IntakeStore(new EntityManager($connection, $shared->getConfiguration(), $events));
    }

    private function page(int $from, int $count): string
    {
        $issues = array_map(static fn (int $number): array => [
            'number' => $number,
            'title' => 'Issue '.$number,
            'body' => '',
            'user' => ['login' => 'ana'],
            'labels' => [['name' => 'intakt']],
            'milestone' => null,
            'updated_at' => '2026-11-10T10:00:00Z',
        ], range($from, $from + $count - 1));

        return json_encode($issues, JSON_THROW_ON_ERROR);
    }

    private function historyAnswer(): string
    {
        $item = [
            '__typename' => 'Issue',
            'updatedAt' => '2026-11-10T10:00:00Z',
            'body' => '',
            'labels' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [['name' => 'intakt']]],
            'author' => ['__typename' => 'User', 'login' => 'ana'],
            'userContentEdits' => ['nodes' => []],
            'timelineItems' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [['__typename' => 'LabeledEvent', 'actor' => ['__typename' => 'User', 'login' => 'ana'], 'label' => ['name' => 'intakt']]]],
        ];

        return json_encode(['data' => ['repository' => ['issueOrPullRequest' => $item]]], JSON_THROW_ON_ERROR);
    }

    private function sourceOver(IssueReads $reads, IssueHistories $histories): GitHubWorkSource
    {
        $accounts = self::createStub(SteeringAccounts::class);
        $accounts->method('steers')->willReturnCallback(function (string $project, string $account): bool {
            $this->asked[] = $account;
            if ($this->accessFailure instanceof TrustNotChecked) {
                throw $this->accessFailure;
            }

            return in_array($account, $this->writers, true);
        });
        $listing = new CompleteListing($reads, $histories, $accounts, $this->store, new EligibilityLabels(['alpha' => ['eligibility_label' => $this->eligibilityLabel]]), new IssueReferences(), $this->logger);

        return new GitHubWorkSource($listing, $histories, $accounts, new IssueReferences(), $this->store, new PullRequestsOfTasks($this->branches, $histories, $accounts), $this->logger);
    }
}
