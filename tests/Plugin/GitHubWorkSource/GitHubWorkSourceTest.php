<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubWorkSource\Domain\HeldIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\Priority;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\EligibilityLabels;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\GitHubWorkSource;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\IntakeSnapshots;
use Intakt\Plugin\WorkSource\Contract\HeldTask;
use Intakt\Plugin\WorkSource\Contract\NothingPicked;
use Intakt\Plugin\WorkSource\Contract\Task;
use Intakt\Plugin\WorkSource\Contract\TaskNotQueued;
use Intakt\Plugin\WorkSource\Contract\WorkerState;
use Intakt\Plugin\WorkSource\Contract\WorkPick;
use Intakt\Plugin\WorkSource\Contract\WorkQueue;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[Group('persistence')]
#[CoversClass(GitHubWorkSource::class)]
#[CoversClass(IntakeSnapshots::class)]
#[CoversClass(EligibilityLabels::class)]
#[CoversClass(IntakeValidator::class)]
#[UsesClass(QueuedIssue::class)]
#[UsesClass(IntakeRules::class)]
#[UsesClass(IntakePlan::class)]
#[UsesClass(HeldIssue::class)]
#[UsesClass(IssueReferences::class)]
#[UsesClass(ItemReading::class)]
#[UsesClass(Priority::class)]
#[UsesClass(IssueListing::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(TrustNotChecked::class)]
#[UsesClass(Task::class)]
#[UsesClass(TaskNotQueued::class)]
#[UsesClass(HeldTask::class)]
#[UsesClass(WorkPick::class)]
#[UsesClass(WorkQueue::class)]
#[UsesClass(WorkerState::class)]
final class GitHubWorkSourceTest extends KernelTestCase
{
    private EntityManagerInterface $entities;
    private RecordedIssueReads $reads;
    private GrantedAccounts $accounts;
    private GitHubWorkSource $source;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->reads = new RecordedIssueReads();
        $this->accounts = new GrantedAccounts();
        $this->source = $this->sourceWith(new EligibilityLabels([]));
    }

    public function testAnOpenTaskWithTheEligibilityLabelIsPickedForAFreeWorker(): void
    {
        $this->reads->lists([Issues::task(7)]);

        self::assertSame(7, $this->source->nextWork('alpha', WorkerState::Free)->task?->number);
    }

    public function testAnIdeaIsNeverPicked(): void
    {
        $this->reads->lists([Issues::task(7, ['intakt', 'idea'])]);

        self::assertSame(NothingPicked::NoTaskReady, $this->source->nextWork('alpha', WorkerState::Free)->nothing);
    }

    public function testAnIssueWithoutTheEligibilityLabelIsNeverPicked(): void
    {
        $this->reads->lists([Issues::task(7, ['bug'])]);

        self::assertSame(NothingPicked::NoTaskReady, $this->source->nextWork('alpha', WorkerState::Free)->nothing);
    }

    public function testTheProjectsOwnEligibilityLabelIsTheOneThatCounts(): void
    {
        $source = $this->sourceWith(new EligibilityLabels(['alpha' => ['eligibility_label' => 'build']]));
        $this->reads->lists([Issues::task(1), Issues::task(2, ['build'])]);

        self::assertSame(2, $source->nextWork('alpha', WorkerState::Free)->task?->number);
    }

    public function testAnIssueWrittenByAnAccountWithoutWriteAccessIsNotEligible(): void
    {
        $this->reads->lists([Issues::task(7, ['intakt'], with: ['author' => 'mallory'])]);
        $this->reads->editors[7] = 'mallory';

        self::assertSame([], $this->source->queue('alpha')->ready());
    }

    public function testAnEditByAnUntrustedAccountMakesTheTaskIneligibleUntilATrustedAccountEditsIt(): void
    {
        $this->reads->lists([Issues::task(7)]);
        self::assertCount(1, $this->source->queue('alpha')->ready());

        $this->reads->editors[7] = 'mallory';
        $this->reads->lists([Issues::task(7, ['intakt'], with: ['updatedAt' => '2026-11-10T11:00:00Z'])]);
        self::assertSame([], $this->source->queue('alpha')->ready());

        $this->reads->editors[7] = 'bo';
        $this->reads->lists([Issues::task(7, ['intakt'], with: ['updatedAt' => '2026-11-10T12:00:00Z'])]);
        self::assertCount(1, $this->source->queue('alpha')->ready());
    }

    public function testWriteAccessIsCheckedAgainBeforeEachBuild(): void
    {
        $this->reads->lists([Issues::task(7), Issues::task(8)]);
        self::assertSame(7, $this->source->nextWork('alpha', WorkerState::Free)->task?->number);
        $checks = $this->accounts->checks;

        $this->accounts->writers = ['bo'];
        $this->reads->lists([Issues::task(7, ['intakt'], with: ['author' => 'bo', 'updatedAt' => '2026-11-10T09:00:00Z']), Issues::task(8)]);
        $this->reads->editors[7] = 'ana';

        self::assertSame(NothingPicked::NoTaskReady, $this->source->nextWork('alpha', WorkerState::Free)->nothing);
        self::assertGreaterThan($checks, $this->accounts->checks);
    }

    public function testATaskWhoseWriterLostWriteAccessAfterItBecameEligibleIsSkippedForTheNextOne(): void
    {
        $this->reads->lists([Issues::task(7, ['intakt'], with: ['author' => 'bo']), Issues::task(8)]);
        self::assertCount(2, $this->source->queue('alpha')->ready());

        $this->accounts->writers = ['ana'];
        $this->reads->listsUnchanged();

        self::assertSame(8, $this->source->nextWork('alpha', WorkerState::Free)->task?->number);
    }

    public function testNothingIsPickedWhenWriteAccessCannotBeChecked(): void
    {
        $this->reads->lists([Issues::task(7)]);
        self::assertCount(1, $this->source->queue('alpha')->ready());
        $this->accounts->unreachable = true;
        $this->reads->listsUnchanged();

        self::assertSame(NothingPicked::TrustNotChecked, $this->source->nextWork('alpha', WorkerState::Free)->nothing);
        self::assertLoggedError('error', 'Write access of a task writer could not be checked, so no task is picked.');
    }

    public function testATaskWaitingOnAnOpenIssueIsHeldAndReleasedWhenAListingNoLongerShowsIt(): void
    {
        $this->reads->lists([Issues::task(1, ['intakt'], with: ['body' => 'Waits for #2']), Issues::task(2, ['bug'])]);
        $held = $this->source->queue('alpha')->held();
        self::assertSame('waits for #2', $held[0]->reason);

        $this->reads->lists([Issues::task(1, ['intakt'], with: ['body' => 'Waits for #2'])]);

        self::assertSame(1, $this->source->nextWork('alpha', WorkerState::Free)->task?->number);
    }

    public function testATaskWaitingOnSeveralIssuesIsReleasedOnlyWhenEveryOneIsClosed(): void
    {
        $waiting = Issues::task(1, ['intakt'], with: ['body' => "Waits for #2\nWaits for #3"]);
        $this->reads->lists([$waiting, Issues::task(3, ['bug'])]);
        self::assertSame([], $this->source->queue('alpha')->ready());

        $this->reads->lists([$waiting]);

        self::assertCount(1, $this->source->queue('alpha')->ready());
    }

    public function testATaskWhoseDependencyFailedForGoodIsHeldWithTheReason(): void
    {
        $this->reads->lists([Issues::task(1, ['intakt'], with: ['body' => 'Waits for #2']), Issues::task(2)]);
        self::assertSame(1, $this->source->queue('alpha')->held()[0]->task->number);
        $this->source->failedForGood('alpha', 2, 'the build ran out of rework rounds');

        $this->reads->lists([Issues::task(1, ['intakt'], with: ['body' => 'Waits for #2']), Issues::task(2)]);
        $reasons = array_map(static fn (HeldTask $held): string => $held->reason, $this->source->queue('alpha')->held());

        self::assertContains('depends on #2, which failed for good: the build ran out of rework rounds', $reasons);
        self::assertSame([], $this->source->queue('alpha')->ready());
    }

    public function testAFailureIsRecordedOnlyForATaskTheListingShowed(): void
    {
        $this->expectException(TaskNotQueued::class);

        $this->source->failedForGood('alpha', 99, 'unknown');
    }

    public function testAWaitOnSeveralIssuesMakesNoReadPerReference(): void
    {
        $this->reads->lists([Issues::task(1, ['intakt'], with: ['body' => 'Waits for #2, #3, #4']), Issues::task(2, ['bug']), Issues::task(3, ['bug']), Issues::task(4, ['bug'])]);

        $this->source->queue('alpha');

        self::assertSame(1, $this->reads->listings);
        self::assertSame(1, $this->reads->editorReads);
    }

    public function testAnUnchangedListingKeepsTheStoredStateOfEveryWaitedForIssue(): void
    {
        $this->reads->lists([Issues::task(1, ['intakt'], with: ['body' => 'Waits for #2']), Issues::task(2, ['bug'])], 'W/"one"');
        self::assertSame([], $this->source->queue('alpha')->ready());

        $this->reads->listsUnchanged();
        $queue = $this->source->queue('alpha');

        self::assertSame([], $queue->ready());
        self::assertSame('waits for #2', $queue->held()[0]->reason);
        self::assertSame([null, 'W/"one"'], $this->reads->validators);
        self::assertSame(1, $this->reads->editorReads);
    }

    public function testAnItemTheListingShowsUnchangedIsNotReadAgain(): void
    {
        $this->reads->lists([Issues::task(1), Issues::task(2)]);
        $this->source->queue('alpha');

        $this->reads->lists([Issues::task(1), Issues::task(2, ['intakt'], with: ['updatedAt' => '2026-11-11T10:00:00Z'])]);
        $this->source->queue('alpha');

        self::assertSame(3, $this->reads->editorReads);
    }

    #[DataProvider('workersThatDoNotTakeWork')]
    public function testAWorkerThatWillNotTakeWorkMakesNoForgeCall(WorkerState $state): void
    {
        $this->reads->lists([Issues::task(1)]);

        $pick = $this->source->nextWork('alpha', $state);

        self::assertSame(NothingPicked::WorkerNotTakingWork, $pick->nothing);
        self::assertSame(0, $this->reads->listings);
        self::assertSame(0, $this->reads->editorReads);
        self::assertSame(0, $this->accounts->checks);
    }

    /**
     * @return iterable<string, array{WorkerState}>
     */
    public static function workersThatDoNotTakeWork(): iterable
    {
        yield 'no free slot' => [WorkerState::NoFreeSlot];
        yield 'paused' => [WorkerState::Paused];
        yield 'draining' => [WorkerState::Draining];
        yield 'held by the budget' => [WorkerState::HeldByBudget];
    }

    public function testAFailedListingPicksNothingAndLogsTheReason(): void
    {
        $this->reads->fails();

        $pick = $this->source->nextWork('alpha', WorkerState::Free);

        self::assertSame(NothingPicked::ListingIncomplete, $pick->nothing);
        self::assertLoggedError('error', 'The open issues of the project were not listed completely, so no task is picked.');
    }

    public function testAFailedListingClosesNoWait(): void
    {
        $this->reads->lists([Issues::task(1, ['intakt'], with: ['body' => 'Waits for #2']), Issues::task(2, ['bug'])], 'W/"one"');
        $this->source->queue('alpha');
        $this->reads->fails();
        self::assertFalse($this->source->queue('alpha')->complete);
        self::assertLoggedError('error', 'The open issues of the project were not listed completely, so no task is picked.');

        $this->reads->listsUnchanged();

        self::assertSame('waits for #2', $this->source->queue('alpha')->held()[0]->reason);
    }

    public function testAListingInProgressElsewhereIsNotListedAgain(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $source = $this->sourceWith(new EligibilityLabels([]), $locks);
        $lock = $locks->createLock('work-source-listing-'.hash('sha256', 'alpha'));
        $lock->acquire();
        $this->reads->lists([Issues::task(1)]);

        self::assertFalse($source->queue('alpha')->complete);

        self::assertSame(0, $this->reads->listings);
        self::assertLoggedError('error', 'The open issues of the project were not listed completely, so no task is picked.');
    }

    private function sourceWith(EligibilityLabels $labels, ?LockFactory $locks = null): GitHubWorkSource
    {
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(LoggerInterface::class, $logger);
        $snapshots = new IntakeSnapshots($this->entities, $locks ?? new LockFactory(new InMemoryStore()), $this->reads, $this->accounts, new IssueReferences(), $labels);

        return new GitHubWorkSource($snapshots, $labels, $this->accounts, $logger);
    }
}
