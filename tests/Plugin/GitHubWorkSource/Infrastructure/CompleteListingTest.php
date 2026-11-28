<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource\Infrastructure;

use Closure;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Build\Contract\UmbrellaTasks;
use Intakt\Core\Build\Infrastructure\RecordedSplitUmbrellas;
use Intakt\Core\Build\UseCase\SizeTask\SizingRecords;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Core\Watcher\Contract\ProjectEligibilityLabel;
use Intakt\Core\Watcher\Contract\ProjectNamesNoEligibilityLabel;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueHistories;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueReads;
use Intakt\Plugin\GitHubForge\Infrastructure\IssueFields;
use Intakt\Plugin\GitHubWorkSource\Domain\HeldIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\ListingReplacedMeanwhile;
use Intakt\Plugin\GitHubWorkSource\Domain\ListingRows;
use Intakt\Plugin\GitHubWorkSource\Domain\ProjectHeldByAnotherWriter;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\SyncState;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\CompleteListing;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\IntakeStore;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Plugin\GitHubForge\RecordedIssueApi;
use Intakt\Tests\Plugin\GitHubWorkSource\CommitOnFirstFailureLoad;
use Intakt\Tests\Plugin\GitHubWorkSource\Issues;
use Intakt\Tests\Plugin\GitHubWorkSource\RecordedIssueReads;
use Intakt\Tests\Plugin\GitHubWorkSource\ScriptedHistories;
use Intakt\Tests\Plugin\GitHubWorkSource\WriterBetweenWriteAndCommit;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LogLevel;
use Symfony\Component\ErrorHandler\BufferingLogger;

#[Group('persistence')]
#[CoversClass(CompleteListing::class)]
#[CoversClass(StoredIssue::class)]
#[CoversClass(IntakeStore::class)]
#[UsesClass(EligibilityLabels::class)]
#[UsesClass(ProjectEligibilityLabel::class)]
#[UsesClass(ProjectNamesNoEligibilityLabel::class)]
#[UsesClass(GitHubIssueReads::class)]
#[UsesClass(IssueFields::class)]
#[UsesClass(GitHubIssueHistories::class)]
#[UsesClass(IssueListing::class)]
#[UsesClass(IntakeRules::class)]
#[UsesClass(IntakePlan::class)]
#[UsesClass(HeldIssue::class)]
#[UsesClass(IssueReferences::class)]
#[UsesClass(ItemReading::class)]
#[UsesClass(IntakeValidator::class)]
#[UsesClass(ListingReplacedMeanwhile::class)]
#[UsesClass(ListingRows::class)]
#[UsesClass(ProjectHeldByAnotherWriter::class)]
#[UsesClass(QueuedIssue::class)]
#[UsesClass(SyncState::class)]
#[UsesClass(Vouching::class)]
#[UsesClass(RecordedSplitUmbrellas::class)]
#[UsesClass(UmbrellaTasks::class)]
#[UsesClass(SizingRecords::class)]
final class CompleteListingTest extends KernelTestCase
{
    private const string VALIDATOR = 'W/"issues-1"';
    private const string SHORT = 'The open-issue listing of project {project} came back short, so nothing is picked and no wait closes this tick: {reason}';

    private IntakeStore $store;
    private RecordedIssueReads $reads;
    private ScriptedHistories $histories;
    private BufferingLogger $logger;
    private InMemoryLedger $ledger;

    public function testAWaitOnSeveralIssuesIsAnsweredFromTheListingWithoutAReadPerReference(): void
    {
        $issues = [Issues::task(3), Issues::task(4), Issues::task(10, ['intakt'], ['body' => 'Waits for #3, #4 and #5'])];
        $this->reads->lists($issues);
        $this->planOf();
        $this->reads->lists($issues);

        $plan = $this->planOf();

        self::assertSame(['waits for #3, #4'], $this->reasonsOf($plan));
        self::assertSame(2, $this->reads->listings);
        self::assertSame([3, 4, 10], $this->histories->read);
    }

    public function testATaskWhoseSplitPlanTheLedgerRecordsIsHeldAndItsPartsAreReady(): void
    {
        $this->ledger->append(new LedgerEvent(LedgerEventKind::TaskSplitPlanned, json_encode(['project' => 'alpha', 'task' => '7', 'run' => 'r1', 'parts' => [], 'labels' => []], JSON_THROW_ON_ERROR), SizingRecords::keyOf('alpha', 7)));
        $this->reads->lists([Issues::task(7), Issues::task(100), Issues::task(101)]);

        $plan = $this->planOf();

        self::assertSame([100, 101], array_map(static fn (QueuedIssue $task): int => $task->number(), $plan->ready));
        self::assertSame(['split into parts, which are built in its place'], $this->reasonsOf($plan));
    }

    public function testAnUnchangedListingKeepsTheStoredStateOfEveryWaitedForIssue(): void
    {
        $this->reads->lists([Issues::task(3), Issues::task(4), Issues::task(10, ['intakt'], ['body' => 'Waits for #3, #4'])]);
        $this->planOf();
        $this->reads->listsUnchanged();

        $plan = $this->planOf();

        self::assertSame(['waits for #3, #4'], $this->reasonsOf($plan));
        self::assertSame([null, self::VALIDATOR], $this->reads->validators);
        self::assertSame([3, 4, 10], $this->numbersOf($this->store->issuesOf('alpha')));
        self::assertSame(3, count(array_filter($this->store->issuesOf('alpha'), static fn (StoredIssue $issue): bool => $issue->queued()->isTrusted())));
        self::assertSame([3, 4, 10], $this->histories->read);
    }

    public function testMoreReadyIssuesThanOnePageHoldsAreListedInFullWithTheIssueThatSortsFirstOnTheLastPage(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(2, 100), 2 => $this->page(1, 1)];
        $api->namingNext = [1];
        $api->stable = true;
        $api->graphql = $this->historyAnswer('Issue', '', ['intakt']);

        $plan = $this->listingOver($api->reads(), $api->histories())->planOf('alpha');

        self::assertInstanceOf(IntakePlan::class, $plan);
        self::assertCount(101, $plan->ready);
        self::assertSame(1, array_first($plan->ready)?->number());
    }

    public function testALaterPageThatFailsLeavesTheListingShortSoNothingIsClosedAndTheReasonIsLogged(): void
    {
        $this->reads->lists([Issues::task(7), Issues::task(8, ['intakt'], ['body' => 'Waits for #7'])]);
        $this->planOf();
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(8, 100), 2 => '502'];
        $api->namingNext = [1];

        $plan = $this->listingOver($api->reads(), $this->histories)->planOf('alpha');

        self::assertNull($plan);
        self::assertSame([7, 8], $this->numbersOf($this->store->issuesOf('alpha')));
        self::assertSame(self::VALIDATOR, $this->store->syncStateOf('alpha')->validator);
        self::assertSame(['GitHub answered 502 to a read.'], $this->shortListingReasons());
    }

    public function testAHistoryThatCannotBeReadLeavesTheListingShortAndWritesNothing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(7, 1)];
        $api->graphql = '502';

        $plan = $this->listingOver($api->reads(), $api->histories())->planOf('alpha');

        self::assertNull($plan);
        self::assertSame([], $this->store->issuesOf('alpha'));
        self::assertSame(['GitHub answered 502 to a read.'], $this->shortListingReasons());
    }

    public function testAnOpenPullRequestWithAClosingKeywordHasItsHistoryReadWithoutLeavingTheListingShort(): void
    {
        $api = new RecordedIssueApi();
        $api->graphql = $this->historyAnswer('PullRequest', 'Closes #7', []);
        $this->reads->lists([Issues::pullRequest(12, 'Closes #7')]);

        $plan = $this->listingOver($this->reads, $api->histories())->planOf('alpha');

        self::assertInstanceOf(IntakePlan::class, $plan);
        self::assertSame([7 => 12], $plan->pullRequests);
        self::assertSame([], $this->shortListingReasons());
    }

    // The update time has whole seconds, so an edit in the second of the listing keeps it: only the text the history holds tells whose text it vouches for.
    public function testABodyEditedInTheSecondOfTheListingLeavesTheListingShortInsteadOfVouchingForTheListedText(): void
    {
        $api = new RecordedIssueApi();
        $api->graphql = $this->historyAnswer('PullRequest', 'No closing keyword', []);
        $this->reads->lists([Issues::pullRequest(12, 'Closes #7')]);

        $plan = $this->listingOver($this->reads, $api->histories())->planOf('alpha');

        self::assertNull($plan);
        self::assertSame([], $this->store->issuesOf('alpha'));
        self::assertSame(['GitHub holds #12 with other text or labels than it was listed with, so its history is not of the listed item.'], $this->shortListingReasons());
    }

    public function testLabelsChangedInTheSecondOfTheListingLeaveTheListingShortInsteadOfVouchingForTheListedLabels(): void
    {
        $api = new RecordedIssueApi();
        $api->graphql = $this->historyAnswer('Issue', '', ['intakt', 'ready']);
        $this->reads->lists([Issues::task(7)]);

        $plan = $this->listingOver($this->reads, $api->histories())->planOf('alpha');

        self::assertNull($plan);
        self::assertSame([], $this->store->issuesOf('alpha'));
        self::assertSame(['GitHub holds #7 with other text or labels than it was listed with, so its history is not of the listed item.'], $this->shortListingReasons());
    }

    public function testABodyEditedInTheSecondOfTheListingWithTheSameReferencesIsVouchedForTheListedReferences(): void
    {
        $api = new RecordedIssueApi();
        $api->graphql = $this->historyAnswer('Issue', "Waits for #3\n\nThanks for the review.", ['intakt']);
        $this->reads->lists([Issues::task(7, ['intakt'], ['body' => 'Waits for #3'])]);

        $plan = $this->listingOver($this->reads, $api->histories())->planOf('alpha');

        self::assertInstanceOf(IntakePlan::class, $plan);
        self::assertTrue($this->rowOf(7)->isVouched());
        self::assertSame([], $this->shortListingReasons());
    }

    public function testAnItemChangedBetweenTheListingAndItsHistoryReadLeavesTheListingShortAndWritesNothing(): void
    {
        $api = new RecordedIssueApi();
        $api->graphql = str_replace('2026-11-10T10:00:00Z', '2026-11-10T10:05:00Z', $this->historyAnswer('Issue', '', ['intakt']));
        $this->reads->lists([Issues::task(7)]);

        $plan = $this->listingOver($this->reads, $api->histories())->planOf('alpha');

        self::assertNull($plan);
        self::assertSame([], $this->store->issuesOf('alpha'));
        self::assertSame(['GitHub changed #7 after it was listed, so its history may be of other text and labels.'], $this->shortListingReasons());
    }

    public function testRemovingTheRowOfAClosedIssueKeepsItsFailedForGoodReasonSoItsDependentsStayHeld(): void
    {
        $this->reads->lists([Issues::task(7), Issues::task(8, ['intakt'], ['body' => 'Waits for #7'])]);
        $this->planOf();
        $this->store->failForGood('alpha', 7, 'the build ran out of attempts');
        $this->reads->lists([Issues::task(8, ['intakt'], ['body' => 'Waits for #7'])], 'W/"issues-2"');

        $plan = $this->planOf();

        self::assertSame([8], $this->numbersOf($this->store->issuesOf('alpha')));
        self::assertSame([], $plan->ready);
        self::assertSame(['depends on #7, which failed for good: the build ran out of attempts'], $this->reasonsOf($plan));
    }

    public function testAMilestoneDueDateThatChangesWithoutTheUpdateTimeMovingIsTakenFromTheListing(): void
    {
        $this->reads->lists([Issues::task(7, ['intakt'], ['due' => '2026-12-20T00:00:00Z'])]);
        $this->planOf();
        $this->reads->lists([Issues::task(7, ['intakt'], ['due' => '2026-12-01T00:00:00Z'])], 'W/"issues-2"');

        $plan = $this->planOf();

        self::assertSame('2026-12-01', array_first($plan->ready)?->milestoneDue()?->format('Y-m-d'));
        self::assertSame('2026-12-01', array_first($this->store->issuesOf('alpha'))?->queued()->milestoneDue()?->format('Y-m-d'));
        self::assertSame([7], $this->histories->read);
    }

    public function testAnItemWhoseUpdateTimeMovedHasItsHistoryReadAgain(): void
    {
        $this->reads->lists([Issues::task(7)]);
        $this->planOf();
        $this->reads->lists([Issues::task(7, ['intakt'], ['updatedAt' => '2026-11-11T10:00:00Z'])], 'W/"issues-2"');

        $this->planOf();

        self::assertSame([7, 7], $this->histories->read);
    }

    public function testWaitsChangedWithoutTheUpdateTimeMovingHaveTheHistoryReadAgain(): void
    {
        $this->reads->lists([Issues::task(7, ['intakt'], ['body' => 'Waits for #3'])]);
        $this->planOf();
        $this->reads->lists([Issues::task(7, ['intakt'], ['body' => 'Waits for #4'])], 'W/"issues-2"');

        $this->planOf();

        self::assertSame([7, 7], $this->histories->read);
    }

    public function testLabelsChangedWithoutTheUpdateTimeMovingHaveTheHistoryReadAgain(): void
    {
        $this->reads->lists([Issues::task(7, ['intakt'])]);
        $this->planOf();
        $this->reads->lists([Issues::task(7, ['intakt', 'priority:high'])], 'W/"issues-2"');

        $this->planOf();

        self::assertSame([7, 7], $this->histories->read);
    }

    public function testNumericLabelsListedInAnotherOrderKeepTheStoredVouching(): void
    {
        $this->reads->lists([Issues::task(7, ['intakt', '1', '01'])]);
        $this->planOf();
        $this->reads->lists([Issues::task(7, ['01', 'intakt', '1'])], 'W/"issues-2"');

        $this->planOf();

        self::assertSame([7], $this->histories->read);
    }

    public function testNumericLabelsTheHistoryHoldsInAnotherOrderAreTheListedLabels(): void
    {
        $api = new RecordedIssueApi();
        $api->graphql = $this->historyAnswer('Issue', '', ['01', '1', 'intakt']);
        $this->reads->lists([Issues::task(7, ['intakt', '1', '01'])]);

        $plan = $this->listingOver($this->reads, $api->histories())->planOf('alpha');

        self::assertInstanceOf(IntakePlan::class, $plan);
        self::assertSame([], $this->shortListingReasons());
    }

    public function testAfterTheEligibilityLabelChangesAnUnchangedListingReadsTheHistoryOfEveryRowThatBecameACandidate(): void
    {
        $this->reads->lists([Issues::task(7, ['ready']), Issues::task(8, ['intakt'])]);
        $this->planOf();
        $this->reads->listsUnchanged();

        $plan = $this->listingOver($this->reads, $this->histories, 'ready')->planOf('alpha');

        self::assertInstanceOf(IntakePlan::class, $plan);
        self::assertSame([7], $this->numbersOf($plan->ready));
        self::assertSame([8, 7], $this->histories->read);
        self::assertTrue($this->rowOf(7)->queued()->isTrusted());
    }

    public function testATaskCarryingOnlyTheLoopsLabelIsNotPlannedForAProjectWhoseWatchNamesItsOwnLabel(): void
    {
        $this->reads->lists([Issues::task(7, ['ready']), Issues::task(8, ['intakt'])]);

        $plan = $this->listingWatching(new EligibilityLabels(new ProjectEligibilityLabel('alpha', 'intakt')))->planOf('alpha');

        self::assertInstanceOf(IntakePlan::class, $plan);
        self::assertSame([8], $this->numbersOf($plan->ready));
        self::assertSame([], $plan->held);
        self::assertSame([8], $this->histories->read);
    }

    public function testEachProjectPlansOnlyTheTasksCarryingTheLabelItsWatchNames(): void
    {
        $watches = new EligibilityLabels(new ProjectEligibilityLabel('alpha', 'intakt'), new ProjectEligibilityLabel('beta', 'ready'));
        $issues = [Issues::task(7, ['ready']), Issues::task(8, ['intakt'])];
        $this->reads->lists($issues);
        $alpha = $this->listingWatching($watches)->planOf('alpha');
        $this->reads->lists($issues);

        $beta = $this->listingWatching($watches)->planOf('beta');

        self::assertInstanceOf(IntakePlan::class, $alpha);
        self::assertInstanceOf(IntakePlan::class, $beta);
        self::assertSame([[8], [7]], [$this->numbersOf($alpha->ready), $this->numbersOf($beta->ready)]);
    }

    public function testAProjectWithoutAWatchSectionLeavesTheListingShortWithoutAForgeCall(): void
    {
        $this->reads->lists([Issues::task(8, ['intakt'])]);

        $plan = $this->listingWatching(new EligibilityLabels(new ProjectEligibilityLabel('beta', 'intakt')))->planOf('alpha');

        self::assertNull($plan);
        self::assertSame(0, $this->reads->listings);
        self::assertSame(['Project "alpha" has no watch section, so it names no eligibility label.'], $this->shortListingReasons());
    }

    public function testAListingAnotherWorkerStoredMeanwhileIsWhatTheWaitsAreAnsweredFrom(): void
    {
        $this->reads->lists([Issues::task(3), Issues::task(10, ['intakt'], ['body' => 'Waits for #3'])]);
        $this->planOf();
        $this->reads->lists([Issues::task(3), Issues::task(10, ['intakt'], ['body' => 'Waits for #3'])], 'W/"issues-2"');
        $this->reads->during = function (): void {
            $newer = $this->store->issuesOf('alpha')[1];
            (void) $this->store->recordIfStill('alpha', $this->store->syncStateOf('alpha'), new ListingRows([$newer], 'W/"issues-3"'));
        };

        $plan = $this->planOf();

        self::assertSame([10], $this->numbersOf($plan->ready));
        self::assertSame('W/"issues-3"', $this->store->syncStateOf('alpha')->validator);
        self::assertSame([10], $this->numbersOf($this->store->issuesOf('alpha')));
    }

    public function testAnOlderListingWithoutAValidatorLosesToANewerOneStoredDuringItAndTheNewerRowsStay(): void
    {
        $this->reads->lists([Issues::task(3), Issues::task(10, ['intakt'], ['body' => 'Waits for #3'])], null);
        $this->planOf();
        $this->reads->lists([Issues::task(3), Issues::task(10, ['intakt'], ['body' => 'Waits for #3'])], null);
        $this->storedMeanwhile([$this->rowOf(10)], null);

        $plan = $this->planOf();

        self::assertSame([10], $this->numbersOf($plan->ready));
        self::assertSame([10], $this->numbersOf($this->store->issuesOf('alpha')));
    }

    public function testTheFirstSyncOfAProjectLosesCleanlyToOneThatCommittedDuringIt(): void
    {
        $this->reads->lists([Issues::task(3), Issues::task(10, ['intakt'], ['body' => 'Waits for #3'])], null);
        $this->storedMeanwhile([$this->vouchedRowOf(Issues::task(10, ['intakt'], ['body' => 'Waits for #3']))], null);

        $plan = $this->planOf();

        self::assertSame([10], $this->numbersOf($plan->ready));
        self::assertSame([10], $this->numbersOf($this->store->issuesOf('alpha')));
        self::assertSame([], $this->shortListingReasons());
    }

    public function testAfterALostWriteARowThatIsACandidateUnderThisSyncsEligibilityLabelIsVouchedBeforeThePlanIsAnswered(): void
    {
        $this->reads->lists([Issues::task(7, ['ready']), Issues::task(8, ['intakt'])]);
        $otherWorkers = null;
        $this->reads->during = function () use (&$otherWorkers): void {
            self::assertInstanceOf(IntakePlan::class, $this->listingOver($this->reads, $this->histories)->planOf('alpha'));
            $otherWorkers = $this->store->syncStateOf('alpha')->generation;
        };

        $plan = $this->listingOver($this->reads, $this->histories, 'ready')->planOf('alpha');

        self::assertInstanceOf(IntakePlan::class, $plan);
        self::assertSame([7], $this->numbersOf($plan->ready));
        self::assertSame([8, 7, 7], $this->histories->read);
        self::assertSame([], $this->shortListingReasons());
        self::assertFalse($this->rowOf(7)->isVouched());
        self::assertSame($otherWorkers, $this->store->syncStateOf('alpha')->generation);
    }

    public function testAHistoryThatCannotBeReadAfterALostWriteLeavesTheListingShort(): void
    {
        $this->reads->lists([Issues::task(7, ['ready'])]);
        $this->storedMeanwhile([$this->rowOfListed(Issues::task(7, ['ready', 'urgent']))], 'W/"issues-2"');

        $plan = $this->listingOver($this->reads, $this->histories, 'ready')->planOf('alpha');

        self::assertNull($plan);
        self::assertSame(['GitHub holds #7 with other text or labels than it was listed with, so its history is not of the listed item.'], $this->shortListingReasons());
    }

    public function testASyncThatCommitsDuringTheHistoryReadAfterALostWriteLeavesTheListingShortSoNoTaskBecomesReadyFromTheOlderRows(): void
    {
        $waiting = Issues::task(10, ['ready'], ['body' => 'Waits for #3']);
        $this->reads->lists([Issues::task(11, ['ready'])]);
        $this->reads->lists([Issues::task(3), $waiting]);
        $this->reads->during = function () use ($waiting): void {
            $closedThreeAndOpenedElevenUnvouched = [$this->vouchedRowOf($waiting), $this->rowOfListed(Issues::task(11, ['ready']))];
            self::assertTrue($this->store->recordIfStill('alpha', $this->store->syncStateOf('alpha'), new ListingRows($closedThreeAndOpenedElevenUnvouched, 'W/"issues-2"')));
            $this->histories->during = function () use ($waiting): void {
                $reopenedThree = [$this->vouchedRowOf(Issues::task(3)), $this->vouchedRowOf($waiting), $this->rowOfListed(Issues::task(11, ['ready']))];
                self::assertTrue($this->store->recordIfStill('alpha', $this->store->syncStateOf('alpha'), new ListingRows($reopenedThree, 'W/"issues-3"')));
            };
        };

        $plan = $this->listingOver($this->reads, $this->histories, 'ready')->planOf('alpha');

        self::assertNull($plan);
        self::assertSame([11], $this->histories->read);
        self::assertSame(['Another worker stored a newer listing of project "alpha" while the stored rows were checked.'], $this->shortListingReasons());
        self::assertSame([3, 10, 11], $this->numbersOf($this->store->issuesOf('alpha')));
        self::assertSame('W/"issues-3"', $this->store->syncStateOf('alpha')->validator);
        self::assertFalse($this->rowOf(11)->isVouched());
    }

    public function testASyncThatCommitsWhileTheFailuresAreReadAfterALostWriteLeavesTheListingShortSoNoTaskBecomesReadyFromTheOlderRows(): void
    {
        $waiting = Issues::task(10, ['ready'], ['body' => 'Waits for #3']);
        $this->reads->lists([$waiting]);
        $this->reads->during = function () use ($waiting): void {
            $closedThree = [$this->vouchedRowOf($waiting), $this->vouchedRowOf(Issues::task(20))];
            self::assertTrue($this->store->recordIfStill('alpha', $this->store->syncStateOf('alpha'), new ListingRows($closedThree, 'W/"issues-2"')));
            $this->store->failForGood('alpha', 20, 'recorded failure');
        };
        $reopenedThree = new CommitOnFirstFailureLoad(function () use ($waiting): void {
            $rows = [$this->vouchedRowOf(Issues::task(3)), $this->vouchedRowOf($waiting), $this->vouchedRowOf(Issues::task(20))];
            self::assertTrue($this->store->recordIfStill('alpha', $this->store->syncStateOf('alpha'), new ListingRows($rows, 'W/"issues-3"')));
        });
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $entities->getEventManager()->addEventListener([Events::postLoad], $reopenedThree);

        $plan = $this->listingOver($this->reads, $this->histories, 'ready')->planOf('alpha');

        self::assertTrue($reopenedThree->committed());
        self::assertNull($plan);
        self::assertSame(['Another worker stored a newer listing of project "alpha" while the stored rows were checked.'], $this->shortListingReasons());
        self::assertSame('W/"issues-3"', $this->store->syncStateOf('alpha')->validator);
    }

    public function testAWriteRefusedWhileAnotherWorkerHasNotCommittedItsListingLeavesTheListingShortWithoutAnException(): void
    {
        $shared = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $shared);
        $file = (string) tempnam(sys_get_temp_dir(), 'intake');
        $first = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        $second = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        try {
            $events = new EventManager();
            $manager = new EntityManager($first, $shared->getConfiguration(), $events);
            new SchemaTool($manager)->createSchema([$manager->getClassMetadata(IntakeValidator::class), $manager->getClassMetadata(StoredIssue::class)]);
            $events->addEventListener([Events::onFlush], new WriterBetweenWriteAndCommit($second));
            $this->store = new IntakeStore($manager);
            $this->reads->lists([Issues::task(7)]);

            $plan = $this->listingOver($this->reads, $this->histories)->planOf('alpha');
            $second->commit();

            self::assertNull($plan);
            self::assertSame(['Another writer holds the intake rows of project "alpha" and has not committed them.'], $this->shortListingReasons());
            self::assertSame([], $this->store->issuesOf('alpha'));
            self::assertSame('winner', $this->store->syncStateOf('alpha')->validator);
        } finally {
            $second->close();
            $first->close();
            unlink($file);
        }
    }

    public function testASyncStateReadRefusedByAnotherWorkersPendingCommitLeavesTheListingShortWithoutAnException(): void
    {
        $this->withAPendingCommit(function (Closure $pend): void {
            $pend();
            $this->reads->lists([Issues::task(7)]);

            self::assertNull($this->listingOver($this->reads, $this->histories)->planOf('alpha'));
            self::assertSame(['Another writer holds the intake rows of project "alpha" and has not committed them.'], $this->shortListingReasons());
        });
    }

    public function testARowReadRefusedByAnotherWorkersPendingCommitDuringTheListingLeavesTheListingShortWithoutAnException(): void
    {
        $this->withAPendingCommit(function (Closure $pend): void {
            $this->reads->lists([Issues::task(7)]);
            $this->reads->during = $pend;

            self::assertNull($this->listingOver($this->reads, $this->histories)->planOf('alpha'));
            self::assertSame(['Another writer holds the intake rows of project "alpha" and has not committed them.'], $this->shortListingReasons());
        });
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::rowsIn(self::getContainer());
        $this->store = new IntakeStore($entities);
        $this->reads = new RecordedIssueReads();
        $this->histories = new ScriptedHistories($this->reads);
        $this->logger = new BufferingLogger();
        $this->ledger = new InMemoryLedger();
    }

    // A reader keeps its shared lock open, so the writer's commit is refused and leaves its pending lock, which refuses every new read of the file until it commits.
    /**
     * @param Closure(Closure(): void): void $test
     */
    private function withAPendingCommit(Closure $test): void
    {
        $shared = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $shared);
        $file = (string) tempnam(sys_get_temp_dir(), 'intake');
        $options = ['driver' => 'pdo_sqlite', 'path' => $file];
        $intake = DriverManager::getConnection($options);
        $reader = DriverManager::getConnection($options);
        $writer = DriverManager::getConnection($options);
        try {
            $manager = new EntityManager($intake, $shared->getConfiguration(), new EventManager());
            new SchemaTool($manager)->createSchema($manager->getMetadataFactory()->getAllMetadata());
            $intake->executeStatement('PRAGMA busy_timeout=0');
            $writer->executeStatement('PRAGMA busy_timeout=0');
            $this->store = new IntakeStore($manager);
            $pend = static function () use ($reader, $writer): void {
                $reader->beginTransaction();
                $reader->fetchOne('SELECT COUNT(*) FROM work_source_validator');
                $writer->executeStatement('BEGIN IMMEDIATE');
                $writer->executeStatement("INSERT INTO work_source_validator (project, validator, generation) VALUES ('alpha', 'winner', 1)");
                try {
                    $writer->executeStatement('COMMIT');
                    self::fail('The commit was expected to wait for the reader.');
                } catch (RetryableException) {
                }
            };

            $test($pend);
        } finally {
            $intake->close();
            $writer->close();
            $reader->close();
            unlink($file);
        }
    }

    /**
     * @param list<StoredIssue> $rows
     */
    private function storedMeanwhile(array $rows, ?string $validator): void
    {
        $this->reads->during = function () use ($rows, $validator): void {
            self::assertTrue($this->store->recordIfStill('alpha', $this->store->syncStateOf('alpha'), new ListingRows($rows, $validator)));
        };
    }

    private function vouchedRowOf(ListedIssue $listed): StoredIssue
    {
        $references = new IssueReferences();
        $row = StoredIssue::of('alpha', new ItemReading($listed, $references->waitsFor($listed->body), $references->unreadableWaits($listed->body), $references->closedBy($listed->body)));
        $history = new IssueHistory($listed->author, array_map(static fn (string $label): LabelApplication => new LabelApplication($label, 'ana'), [...$listed->labels]), $listed->body, $listed->labels);
        $row->vouch(Vouching::of($listed->author, $history, static fn (string $account): bool => 'ana' === $account));

        return $row;
    }

    private function rowOfListed(ListedIssue $listed): StoredIssue
    {
        $references = new IssueReferences();

        return StoredIssue::of('alpha', new ItemReading($listed, $references->waitsFor($listed->body), $references->unreadableWaits($listed->body), $references->closedBy($listed->body)));
    }

    private function planOf(): IntakePlan
    {
        $plan = $this->listingOver($this->reads, $this->histories)->planOf('alpha');
        self::assertInstanceOf(IntakePlan::class, $plan);

        return $plan;
    }

    /**
     * @return list<string>
     */
    private function shortListingReasons(): array
    {
        return array_values(array_map(static function (mixed $record): string {
            self::assertIsArray($record);
            self::assertSame([LogLevel::WARNING, self::SHORT], [$record[0] ?? null, $record[1] ?? null]);
            $context = $record[2] ?? null;
            self::assertIsArray($context);
            self::assertSame('alpha', $context['project'] ?? null);
            $reason = $context['reason'] ?? null;
            self::assertIsString($reason);

            return $reason;
        }, $this->logger->cleanLogs()));
    }

    private function rowOf(int $number): StoredIssue
    {
        $row = array_find($this->store->issuesOf('alpha'), static fn (StoredIssue $issue): bool => $number === $issue->number());
        self::assertInstanceOf(StoredIssue::class, $row);

        return $row;
    }

    /**
     * @return list<string>
     */
    private function reasonsOf(IntakePlan $plan): array
    {
        return array_map(static fn (HeldIssue $held): string => $held->reason, $plan->held);
    }

    /**
     * @param list<StoredIssue|QueuedIssue> $items
     *
     * @return list<int>
     */
    private function numbersOf(array $items): array
    {
        return array_map(static fn (StoredIssue|QueuedIssue $item): int => $item->number(), $items);
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

    // Written by `ana` and every label applied by her, so only the text, the labels and the update time differ between the tests.
    /**
     * @param list<string> $labels
     */
    private function historyAnswer(string $type, string $body, array $labels): string
    {
        $item = [
            '__typename' => $type,
            'updatedAt' => '2026-11-10T10:00:00Z',
            'body' => $body,
            'labels' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => array_map(static fn (string $label): array => ['name' => $label], $labels)],
            'author' => ['__typename' => 'User', 'login' => 'ana'],
            'userContentEdits' => ['nodes' => []],
            'timelineItems' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => array_map(static fn (string $label): array => ['__typename' => 'LabeledEvent', 'actor' => ['__typename' => 'User', 'login' => 'ana'], 'label' => ['name' => $label]], $labels)],
        ];

        return json_encode(['data' => ['repository' => ['issueOrPullRequest' => $item]]], JSON_THROW_ON_ERROR);
    }

    private function listingOver(IssueReads $reads, IssueHistories $histories, string $label = 'intakt'): CompleteListing
    {
        return $this->listingWatching(new EligibilityLabels(new ProjectEligibilityLabel('alpha', $label)), $reads, $histories);
    }

    private function listingWatching(EligibilityLabels $labels, ?IssueReads $reads = null, ?IssueHistories $histories = null): CompleteListing
    {
        $accounts = self::createStub(SteeringAccounts::class);
        $accounts->method('steers')->willReturnCallback(static fn (string $project, string $account): bool => 'ana' === $account);

        return new CompleteListing($reads ?? $this->reads, $histories ?? $this->histories, $accounts, $this->store, $labels, new IssueReferences(), $this->logger, new RecordedSplitUmbrellas($this->ledger));
    }
}
