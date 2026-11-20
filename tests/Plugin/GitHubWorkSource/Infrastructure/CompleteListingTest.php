<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueHistories;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueReads;
use Intakt\Plugin\GitHubWorkSource\Domain\HeldIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\ListingRows;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\CompleteListing;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\IntakeStore;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Plugin\GitHubForge\RecordedIssueApi;
use Intakt\Tests\Plugin\GitHubWorkSource\Issues;
use Intakt\Tests\Plugin\GitHubWorkSource\RecordedIssueReads;
use Intakt\Tests\Plugin\GitHubWorkSource\ScriptedHistories;
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
#[UsesClass(GitHubIssueReads::class)]
#[UsesClass(GitHubIssueHistories::class)]
#[UsesClass(IssueListing::class)]
#[UsesClass(IntakeRules::class)]
#[UsesClass(IntakePlan::class)]
#[UsesClass(HeldIssue::class)]
#[UsesClass(IssueReferences::class)]
#[UsesClass(ItemReading::class)]
#[UsesClass(ListingRows::class)]
#[UsesClass(QueuedIssue::class)]
#[UsesClass(Vouching::class)]
final class CompleteListingTest extends KernelTestCase
{
    private const string VALIDATOR = 'W/"issues-1"';
    private const string SHORT = 'The open-issue listing of project {project} came back short, so nothing is picked and no wait closes this tick: {reason}';
    private const string LABELED_BY_ANA = '{"data":{"repository":{"issue":{"author":{"__typename":"User","login":"ana"},"userContentEdits":{"nodes":[]},"timelineItems":{"pageInfo":{"hasNextPage":false},"nodes":[{"__typename":"LabeledEvent","actor":{"__typename":"User","login":"ana"},"label":{"name":"intakt"}}]}}}}}';

    private IntakeStore $store;
    private RecordedIssueReads $reads;
    private ScriptedHistories $histories;
    private BufferingLogger $logger;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->store = new IntakeStore($entities);
        $this->reads = new RecordedIssueReads();
        $this->histories = new ScriptedHistories();
        $this->logger = new BufferingLogger();
    }

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
        $api->graphql = self::LABELED_BY_ANA;

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
        self::assertSame(self::VALIDATOR, $this->store->validatorOf('alpha'));
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

    public function testAListingAnotherWorkerStoredMeanwhileIsWhatTheWaitsAreAnsweredFrom(): void
    {
        $this->reads->lists([Issues::task(3), Issues::task(10, ['intakt'], ['body' => 'Waits for #3'])]);
        $this->planOf();
        $this->reads->lists([Issues::task(3), Issues::task(10, ['intakt'], ['body' => 'Waits for #3'])], 'W/"issues-2"');
        $this->reads->during = function (): void {
            $newer = $this->store->issuesOf('alpha')[1];
            (void) $this->store->recordIfStill('alpha', self::VALIDATOR, new ListingRows([$newer], 'W/"issues-3"'));
        };

        $plan = $this->planOf();

        self::assertSame([10], $this->numbersOf($plan->ready));
        self::assertSame('W/"issues-3"', $this->store->validatorOf('alpha'));
        self::assertSame([10], $this->numbersOf($this->store->issuesOf('alpha')));
    }

    private function planOf(): IntakePlan
    {
        $plan = $this->listingOver($this->reads, $this->histories)->planOf('alpha');
        self::assertInstanceOf(IntakePlan::class, $plan);

        return $plan;
    }

    private function listingOver(IssueReads $reads, IssueHistories $histories, string $label = 'intakt'): CompleteListing
    {
        $accounts = self::createStub(SteeringAccounts::class);
        $accounts->method('steers')->willReturnCallback(static fn (string $project, string $account): bool => 'ana' === $account);

        return new CompleteListing($reads, $histories, $accounts, $this->store, new IntakeRules($label), new IssueReferences(), $this->logger);
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
}
