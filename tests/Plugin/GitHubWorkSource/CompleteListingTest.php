<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubWorkSource\Domain\HeldIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeState;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\SyncedListing;
use Intakt\Plugin\GitHubWorkSource\Domain\ValidatorChange;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\CompleteListing;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\IntakeStore;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\IntakeStoreLocked;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Plugin\GitHubForge\RecordedIssueApi;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\Log\LogRecords;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;

#[Group('persistence')]
#[CoversClass(CompleteListing::class)]
#[CoversClass(SyncedListing::class)]
#[CoversClass(StoredIssue::class)]
#[UsesClass(IntakeStore::class)]
#[UsesClass(IntakeStoreLocked::class)]
#[UsesClass(IntakeRules::class)]
#[UsesClass(IntakePlan::class)]
#[UsesClass(HeldIssue::class)]
#[UsesClass(IssueReferences::class)]
#[UsesClass(ItemReading::class)]
#[UsesClass(QueuedIssue::class)]
#[UsesClass(Vouching::class)]
#[UsesClass(ValidatorChange::class)]
#[UsesClass(IntakeState::class)]
#[UsesClass(IntakeValidator::class)]
final class CompleteListingTest extends KernelTestCase
{
    private IntakeStore $store;
    private RecordedIssueHistories $histories;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(LoggerInterface::class, $logger);
        $this->logger = $logger;
        $this->store = new IntakeStore($entities);
        $this->histories = new RecordedIssueHistories();
    }

    public function testAWaitOnSeveralIssuesMakesNoReadPerReference(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page([3, 4, 5, 9 => 'Waits for #3, #4 and #5'])];

        $plan = $this->planOf($this->listing($api->reads())->sync('alpha', new IntakeRules('intakt')));

        self::assertCount(1, $api->requests);
        self::assertStringStartsWith('GET https://api.github.com/repos/acme/widgets/issues?', $api->requests[0]);
        self::assertSame(['waits for #3, #4, #5'], $this->reasonsOf($plan));
    }

    public function testAnUnchangedListingKeepsTheStoredStateOfEveryWaitedForIssue(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page([3, 4, 5, 9 => 'Waits for #3, #4 and #5'])];
        $listing = $this->listing($api->reads());
        $listing->sync('alpha', new IntakeRules('intakt'));
        $historiesRead = count($this->histories->read);
        $api->notModified = true;

        $plan = $this->planOf($listing->sync('alpha', new IntakeRules('intakt')));

        self::assertSame(['W/"issues-1"'], $api->conditions);
        self::assertSame(['waits for #3, #4, #5'], $this->reasonsOf($plan));
        self::assertCount($historiesRead, $this->histories->read);
    }

    public function testAnItemWhoseUpdateTimeDidNotMoveIsVouchedForFromItsStoredRow(): void
    {
        $reads = new RecordedIssueReads();
        $listing = $this->listing($reads);
        $reads->lists([Issues::task(7), Issues::task(8)]);
        $listing->sync('alpha', new IntakeRules('intakt'));
        $reads->lists([Issues::task(7), Issues::task(8, ['intakt'], ['updatedAt' => '2026-11-11T10:00:00Z'])]);

        $synced = $listing->sync('alpha', new IntakeRules('intakt'));

        self::assertSame([7, 8, 8], $this->histories->read);
        self::assertSame([7, 8], array_map(static fn (QueuedIssue $task): int => $task->number(), $this->planOf($synced)->ready));
    }

    public function testMoreReadyIssuesThanOnePageHoldsAreListedInFullAndTheOneThatSortsFirstOnTheLastPageIsFirst(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(range(2, 101)), 2 => $this->page([1])];
        $api->namingNext = [1];
        $api->stable = true;

        $synced = $this->listing($api->reads())->sync('alpha', new IntakeRules('intakt'));

        $ready = $this->planOf($synced)->ready;
        self::assertCount(101, $ready);
        self::assertSame(1, $ready[0]->number());
        self::assertCount(101, $this->store->issuesOf('alpha'));
    }

    public function testALaterPageThatFailsYieldsAShortListingThatClosesNothingAndIsLogged(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page([3, 9 => 'Waits for #3'])];
        $listing = $this->listing($api->reads());
        $listing->sync('alpha', new IntakeRules('intakt'));
        $api->pages = [1 => $this->page(range(9, 108)), 2 => '502'];
        $api->namingNext = [1];

        $synced = $listing->sync('alpha', new IntakeRules('intakt'));

        self::assertFalse($synced->isComplete());
        self::assertSame([], $synced->items);
        self::assertSame([3, 9], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
        self::assertSame('W/"issues-1"', $this->store->stateOf('alpha')->validator);
        $this->assertLoggedWarning('The listing of project alpha came back short, so nothing is picked and no wait closes this tick: GitHub answered 502 to a read.');
    }

    public function testARateLimitedListingIsShortAndRecordsNothing(): void
    {
        $reads = self::createStub(IssueReads::class);
        $reads->method('listOpen')->willThrowException(new ForgeRateLimited(new DateTimeImmutable('2026-11-20T11:00:00+00:00')));

        $synced = $this->listing($reads)->sync('alpha', new IntakeRules('intakt'));

        self::assertSame('The forge is rate limited until 2026-11-20T11:00:00+00:00.', $synced->shortBecause);
        self::assertSame([], $this->store->issuesOf('alpha'));
        $this->assertLoggedWarning('The listing of project alpha came back short, so nothing is picked and no wait closes this tick: The forge is rate limited');
    }

    public function testAHistoryReadThatFailsLeavesTheStoredRowsAsTheyWere(): void
    {
        $reads = new RecordedIssueReads();
        $listing = $this->listing($reads);
        $reads->lists([Issues::task(7)], 'W/"a"');
        $listing->sync('alpha', new IntakeRules('intakt'));
        $reads->lists([Issues::task(7), Issues::task(8)], 'W/"b"');
        $this->histories->failing = true;

        $synced = $listing->sync('alpha', new IntakeRules('intakt'));

        self::assertSame('GitHub answered 502 to a history read.', $synced->shortBecause);
        self::assertSame([7], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
        self::assertSame('W/"a"', $this->store->stateOf('alpha')->validator);
        $this->assertLoggedWarning('came back short, so nothing is picked and no wait closes this tick: GitHub answered 502 to a history read.');
    }

    public function testATrustCheckThatCannotAnswerYieldsAShortListing(): void
    {
        $reads = new RecordedIssueReads();
        $reads->lists([Issues::task(7)]);
        $accounts = self::createStub(SteeringAccounts::class);
        $accounts->method('steers')->willThrowException(TrustNotChecked::becauseGitHubRefused(500));

        $synced = new CompleteListing($reads, $this->histories, $accounts, $this->store, new IssueReferences(), $this->logger)->sync('alpha', new IntakeRules('intakt'));

        self::assertSame('GitHub refused the trust check with status 500.', $synced->shortBecause);
        self::assertSame([], $this->store->issuesOf('alpha'));
        $this->assertLoggedWarning('GitHub refused the trust check with status 500.');
    }

    public function testTheRowOfAClosedIssueGoesButItsFailedForGoodReasonStaysSoItsDependentsStayHeld(): void
    {
        $reads = new RecordedIssueReads();
        $listing = $this->listing($reads);
        $reads->lists([Issues::task(7), Issues::task(8, ['intakt'], ['body' => 'Waits for #7'])]);
        $listing->sync('alpha', new IntakeRules('intakt'));
        $this->store->failForGood('alpha', 7, 'the build ran out of attempts');
        $reads->lists([Issues::task(8, ['intakt'], ['body' => 'Waits for #7'])]);

        $plan = $this->planOf($listing->sync('alpha', new IntakeRules('intakt')));

        self::assertSame([8], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
        self::assertSame([], $plan->ready);
        self::assertSame(['depends on #7, which failed for good: the build ran out of attempts'], $this->reasonsOf($plan));
    }

    public function testAMilestoneDueDateIsTakenFromTheListingWhenTheUpdateTimeDidNotMove(): void
    {
        $reads = new RecordedIssueReads();
        $listing = $this->listing($reads);
        $reads->lists([Issues::task(7, ['intakt'], ['due' => '2026-12-01T00:00:00Z']), Issues::task(8, ['intakt'], ['due' => '2026-12-15T00:00:00Z'])]);
        $listing->sync('alpha', new IntakeRules('intakt'));
        $reads->lists([Issues::task(7, ['intakt'], ['due' => '2027-01-01T00:00:00Z']), Issues::task(8, ['intakt'], ['due' => '2026-12-15T00:00:00Z'])]);

        $synced = $listing->sync('alpha', new IntakeRules('intakt'));

        self::assertSame([8, 7], array_map(static fn (QueuedIssue $task): int => $task->number(), $this->planOf($synced)->ready));
        self::assertSame([7, 8], $this->histories->read);
    }

    public function testAfterTheEligibilityLabelChangesEveryStoredRowFollowsItWithoutItsUpdateTimeMoving(): void
    {
        $reads = new RecordedIssueReads();
        $listing = $this->listing($reads);
        $reads->lists([Issues::task(7, ['ready']), Issues::task(8, ['intakt'])]);
        $listing->sync('alpha', new IntakeRules('intakt'));
        $reads->listsUnchanged();

        $synced = $listing->sync('alpha', new IntakeRules('ready'));

        self::assertSame([7], array_map(static fn (QueuedIssue $task): int => $task->number(), $this->planOf($synced, 'ready')->ready));
        self::assertSame([8, 7], $this->histories->read);
    }

    public function testAnUnchangedAnswerDoesNotRelabelRowsAnotherSyncStoredWhileItsRequestWasInFlight(): void
    {
        $reads = new RecordedIssueReads();
        $listing = $this->listing($reads);
        $rules = new IntakeRules('intakt');
        $reads->lists([Issues::task(7)], 'W/"a"');
        $listing->sync('alpha', $rules);
        $reads->listsUnchanged();
        $reads->during = static function () use ($listing, $rules, $reads): void {
            $reads->lists([Issues::task(8)], 'W/"b"');
            $listing->sync('alpha', $rules);
            $reads->listsUnchanged();
        };

        $synced = $listing->sync('alpha', $rules);

        self::assertSame([8], array_map(static fn (QueuedIssue $task): int => $task->number(), $synced->items));
        self::assertSame([8], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
        self::assertSame('W/"b"', $this->store->stateOf('alpha')->validator);
    }

    public function testAnOlderListingWithoutAValidatorLosesToOneAnotherSyncStoredWhileItWasRead(): void
    {
        $reads = new RecordedIssueReads();
        $listing = $this->listing($reads);
        $rules = new IntakeRules('intakt');
        $reads->lists([Issues::task(7)], null);
        $listing->sync('alpha', $rules);
        $reads->during = static function () use ($listing, $rules, $reads): void {
            $reads->lists([Issues::task(8)], null);
            $listing->sync('alpha', $rules);
            $reads->lists([Issues::task(7)], null);
        };

        $synced = $listing->sync('alpha', $rules);

        self::assertSame([8], array_map(static fn (QueuedIssue $task): int => $task->number(), $synced->items));
        self::assertSame([8], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
    }

    public function testAFirstSyncLosesCleanlyToOneThatStoredTheProjectsFirstListingWhileItWasRead(): void
    {
        $reads = new RecordedIssueReads();
        $listing = $this->listing($reads);
        $rules = new IntakeRules('intakt');
        $reads->during = static function () use ($listing, $rules, $reads): void {
            $reads->lists([Issues::task(8)], null);
            $listing->sync('alpha', $rules);
            $reads->lists([Issues::task(7)], null);
        };

        $synced = $listing->sync('alpha', $rules);

        self::assertSame([8], array_map(static fn (QueuedIssue $task): int => $task->number(), $synced->items));
        self::assertSame([8], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
    }

    public function testRowsAnotherSyncStoredUnderAnotherEligibilityLabelAreJudgedByThisTicksLabelWhenItsWriteIsLost(): void
    {
        $reads = new RecordedIssueReads();
        $listing = $this->listing($reads);
        $reads->lists([Issues::task(7, ['ready'])], 'W/"a"');
        $listing->sync('alpha', new IntakeRules('intakt'));
        $reads->during = static function () use ($listing, $reads): void {
            $reads->lists([Issues::task(7, ['ready'])], 'W/"b"');
            $listing->sync('alpha', new IntakeRules('intakt'));
            $reads->lists([Issues::task(7, ['ready'])], 'W/"c"');
        };

        $synced = $listing->sync('alpha', new IntakeRules('ready'));

        self::assertSame([7], array_map(static fn (QueuedIssue $task): int => $task->number(), $this->planOf($synced, 'ready')->ready));
        self::assertSame('W/"b"', $this->store->stateOf('alpha')->validator);
    }

    public function testAListingAnotherSyncSupersededWhileItWasReadGetsNoHistoryReadOfItsOwn(): void
    {
        $reads = new RecordedIssueReads();
        $listing = $this->listing($reads);
        $rules = new IntakeRules('intakt');
        $histories = $this->histories;
        $reads->during = static function () use ($listing, $rules, $reads, $histories): void {
            $reads->lists([Issues::task(8)], null);
            $listing->sync('alpha', $rules);
            $reads->lists([Issues::task(7)], null);
            $histories->failing = true;
        };

        $synced = $listing->sync('alpha', $rules);

        self::assertTrue($synced->isComplete(), (string) $synced->shortBecause);
        self::assertSame([8], array_map(static fn (QueuedIssue $task): int => $task->number(), $synced->items));
        self::assertSame([8], $this->histories->read);
    }

    public function testAHistoryReadThatFailsForAListingSupersededMeanwhileAnswersTheRowsThatNowCount(): void
    {
        $reads = new RecordedIssueReads();
        $listing = $this->listing($reads);
        $rules = new IntakeRules('intakt');
        $histories = $this->histories;
        $reads->lists([Issues::task(7)], null);
        $histories->during = static function () use ($listing, $rules, $reads, $histories): void {
            $reads->lists([Issues::task(8)], null);
            $listing->sync('alpha', $rules);
            $histories->failing = true;
        };

        $synced = $listing->sync('alpha', $rules);

        self::assertTrue($synced->isComplete(), (string) $synced->shortBecause);
        self::assertSame([8], array_map(static fn (QueuedIssue $task): int => $task->number(), $synced->items));
        self::assertSame([7, 8], $this->histories->read);
    }

    /**
     * @param list<int> $storedBefore
     */
    #[TestWith([[]], 'the first sync of the project')]
    #[TestWith([[6]], 'a later sync')]
    public function testASyncAnotherConnectionOvertookBetweenItsReadAndItsWriteAnswersTheListingThatOneCommitted(array $storedBefore): void
    {
        $shared = $this->twoConnections();
        try {
            $rules = new IntakeRules('intakt');
            $reads = new RecordedIssueReads();
            $overtaken = $this->listingOn($shared->left, $reads);
            if ([] !== $storedBefore) {
                $reads->lists(array_map(Issues::task(...), $storedBefore), null);
                $overtaken->sync('alpha', $rules);
            }

            $reads->lists([Issues::task(7)], null);
            $otherReads = new RecordedIssueReads();
            $otherReads->lists([Issues::task(8)], null);
            $other = $this->listingOn($shared->right, $otherReads);
            $shared->beforeTheNextLeftFlush(static fn (): SyncedListing => $other->sync('alpha', $rules));

            $synced = $overtaken->sync('alpha', $rules);

            self::assertTrue($synced->isComplete(), (string) $synced->shortBecause);
            self::assertSame([8], array_map(static fn (QueuedIssue $task): int => $task->number(), $synced->items));
            self::assertSame([8], array_map(static fn (StoredIssue $issue): int => $issue->number(), new IntakeStore($shared->left)->issuesOf('alpha')));
        } finally {
            $shared->close();
        }
    }

    public function testAStoreAnotherWriterKeepsLockedEndsTheTickShortWithTheReasonAndRecordsNothing(): void
    {
        $shared = $this->twoConnections();
        try {
            $reads = new RecordedIssueReads();
            $reads->lists([Issues::task(7)], null);
            $shared->holdTheWriteLockOnTheRight();

            $synced = $this->listingOn($shared->left, $reads)->sync('alpha', new IntakeRules('intakt'));

            $shared->releaseTheWriteLockOnTheRight();
            self::assertSame('The stored listing of project "alpha" stayed locked by another writer for 3 attempts.', $synced->shortBecause);
            self::assertSame([], $synced->items);
            self::assertSame([], new IntakeStore($shared->left)->issuesOf('alpha'));
            $this->assertLoggedWarning('came back short, so nothing is picked and no wait closes this tick: The stored listing of project "alpha" stayed locked by another writer for 3 attempts.');
        } finally {
            $shared->close();
        }
    }

    private function twoConnections(): TwoConnections
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);

        return TwoConnections::mappedBy($entities->getConfiguration());
    }

    private function listingOn(EntityManagerInterface $entities, IssueReads $reads): CompleteListing
    {
        return new CompleteListing($reads, $this->histories, new GrantedAccounts(), new IntakeStore($entities), new IssueReferences(), $this->logger);
    }

    private function listing(IssueReads $reads): CompleteListing
    {
        return new CompleteListing($reads, $this->histories, new GrantedAccounts(), $this->store, new IssueReferences(), $this->logger);
    }

    private function planOf(SyncedListing $synced, string $label = 'intakt'): IntakePlan
    {
        self::assertTrue($synced->isComplete(), (string) $synced->shortBecause);

        return new IntakeRules($label)->planOf($synced->items, $this->store->failuresOf('alpha'));
    }

    /**
     * @return list<string>
     */
    private function reasonsOf(IntakePlan $plan): array
    {
        return array_map(static fn (HeldIssue $held): string => $held->reason, $plan->held);
    }

    private function assertLoggedWarning(string $part): void
    {
        $warnings = array_filter(LogRecords::ofThisRun()?->all() ?? [], static fn (array $record): bool => 'warning' === $record['level'] && str_contains($record['message'], $part));

        self::assertCount(1, $warnings, 'Expected one warning containing: '.$part);
    }

    /**
     * @param array<int, int|string> $issues
     */
    private function page(array $issues): string
    {
        $listed = [];
        foreach ($issues as $key => $value) {
            [$number, $body] = is_string($value) ? [$key, $value] : [$value, ''];
            $listed[] = ['number' => $number, 'title' => 'Task '.$number, 'body' => $body, 'user' => ['login' => 'ana'], 'labels' => [['name' => 'intakt']], 'milestone' => null, 'updated_at' => '2026-11-10T10:00:00Z'];
        }

        return json_encode($listed, JSON_THROW_ON_ERROR);
    }
}
