<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueReads;
use Intakt\Plugin\GitHubWorkSource\Domain\HeldIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\OpenItems;
use Intakt\Plugin\GitHubWorkSource\Domain\Priority;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredItem;
use Intakt\Plugin\GitHubWorkSource\Domain\TaskFailure;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\IntakeSnapshots;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Plugin\GitHubForge\RecordedIssueApi;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

// The plans are made here as the pick will make them, with every item vouched for, because what a stored row is worth shows only in the order and the holds it yields.
#[Group('persistence')]
#[CoversClass(IntakeSnapshots::class)]
#[CoversClass(StoredItem::class)]
#[CoversClass(IntakeValidator::class)]
#[CoversClass(TaskFailure::class)]
#[CoversClass(OpenItems::class)]
#[UsesClass(GitHubIssueReads::class)]
#[UsesClass(IssueListing::class)]
#[UsesClass(ListedIssue::class)]
#[UsesClass(IssueHistory::class)]
#[UsesClass(LabelApplication::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(ForgeRateLimited::class)]
#[UsesClass(IntakeRules::class)]
#[UsesClass(IntakePlan::class)]
#[UsesClass(HeldIssue::class)]
#[UsesClass(IssueReferences::class)]
#[UsesClass(ItemReading::class)]
#[UsesClass(QueuedIssue::class)]
#[UsesClass(Priority::class)]
#[UsesClass(Vouching::class)]
final class CompleteListingTest extends KernelTestCase
{
    private const string NOT_COMPLETE = 'The open issues of the project were not listed completely, so no task is picked and no wait is closed.';

    private EntityManagerInterface $entities;
    private LockFactory $locks;
    private RecordedIssueReads $reads;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->locks = new LockFactory(new InMemoryStore());
        $this->reads = new RecordedIssueReads();
    }

    public function testAWaitOnSeveralIssuesIsAnsweredFromTheOneListingWithoutAReadPerReference(): void
    {
        $this->reads->lists([Issues::task(1, with: ['body' => 'Waits for #2 and #3']), Issues::task(2), Issues::task(3)]);

        $plan = $this->planOf($this->tick());

        self::assertSame('waits for #2, #3', $this->reasonOf(1, $plan));
        self::assertSame(1, $this->reads->listings);
    }

    public function testAnUnchangedListingKeepsTheStoredStateOfEveryWaitedForIssue(): void
    {
        $this->reads->lists([Issues::task(1, with: ['body' => 'Waits for #2 and #3']), Issues::task(2), Issues::task(3)]);
        $this->tick();
        $this->reads->listsUnchanged();

        $plan = $this->planOf($this->tick());

        self::assertSame('waits for #2, #3', $this->reasonOf(1, $plan));
        self::assertSame([null, 'W/"issues-1"'], $this->reads->validators);
    }

    public function testAnItemWhoseUpdatedAtDidNotMoveIsAnsweredFromItsStoredRow(): void
    {
        $this->reads->lists([Issues::task(1, with: ['body' => 'Waits for #2']), Issues::task(2)]);
        $this->tick();
        $this->reads->lists([Issues::task(1), Issues::task(2)]);

        self::assertSame('waits for #2', $this->reasonOf(1, $this->planOf($this->tick())));
    }

    public function testAnItemWhoseUpdatedAtMovedIsReadFromTheListing(): void
    {
        $this->reads->lists([Issues::task(1, with: ['body' => 'Waits for #2']), Issues::task(2)]);
        $this->tick();
        $this->reads->lists([Issues::task(1, with: ['updatedAt' => '2026-11-11T10:00:00Z']), Issues::task(2)]);

        self::assertSame([1, 2], $this->readyOf($this->planOf($this->tick())));
    }

    public function testMoreReadyIssuesThanOnePageHoldsAreListedInFullWithTheFirstOnTheLastPage(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 100), 3 => $this->page(201, 49, ['intakt', 'priority:urgent'])];
        $api->namingNext = [1, 2];

        $ready = $this->readyOf($this->planOf($this->tickOver($api->reads())));

        self::assertCount(249, $ready);
        self::assertSame(201, $ready[0]);
    }

    public function testALaterPageThatFailsYieldsAShortListingThatClosesNothingAndLogsTheReason(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->pageOf([...$this->issues(1, 1, body: 'Waits for #2'), ...$this->issues(2, 1)])];
        $this->tickOver($api->reads());
        $api->pages = [1 => $this->page(1, 100), 2 => '502'];
        $api->namingNext = [1];

        $short = $this->tickOver($api->reads());

        self::assertNull($short);
        self::assertLoggedError('error', self::NOT_COMPLETE);
        self::assertInstanceOf(StoredItem::class, $this->entities->find(StoredItem::class, ['project' => 'alpha', 'number' => 2]));
        self::assertSame('W/"issues-1"', $this->entities->find(IntakeValidator::class, 'alpha')?->value());
        $api->notModified = true;
        self::assertSame('waits for #2', $this->reasonOf(1, $this->planOf($this->tickOver($api->reads()))));
    }

    public function testALaterPageThatIsRateLimitedYieldsAShortListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => '429'];
        $api->namingNext = [1];

        self::assertNull($this->tickOver($api->reads()));
        self::assertLoggedError('error', self::NOT_COMPLETE);
        self::assertSame([], $this->entities->getRepository(StoredItem::class)->findAll());
    }

    public function testAListingWhileAnotherOfTheSameProjectRunsReadsNothing(): void
    {
        $this->reads->lists([Issues::task(1)]);
        $snapshots = $this->snapshots($this->reads);
        $inner = false;
        $this->reads->during = static function () use ($snapshots, &$inner): void {
            $inner = $snapshots->current('alpha');
        };

        $outer = $snapshots->current('alpha');

        self::assertNull($inner);
        self::assertInstanceOf(OpenItems::class, $outer);
        self::assertSame(1, $this->reads->listings);
    }

    public function testRemovingTheStoredRowOfAClosedTaskKeepsItsFailureSoItsDependentsStayHeld(): void
    {
        $this->reads->lists([Issues::task(5), Issues::task(6, with: ['body' => 'Waits for #5'])]);
        $this->tick();
        $this->entities->persist(new TaskFailure('alpha', 5, 'the tests never passed'));
        $this->entities->flush();
        $this->reads->lists([Issues::task(6, with: ['body' => 'Waits for #5'])]);

        $plan = $this->planOf($this->tick());

        self::assertNull($this->entities->find(StoredItem::class, ['project' => 'alpha', 'number' => 5]));
        self::assertSame('depends on #5, which failed for good: the tests never passed', $this->reasonOf(6, $plan));
    }

    public function testTheMilestoneDueDateIsTakenFromEveryListingEvenWhenUpdatedAtDidNotMove(): void
    {
        $this->reads->lists([Issues::task(1, with: ['due' => '2026-12-20T08:00:00Z']), Issues::task(2, with: ['due' => '2026-12-10T08:00:00Z'])]);
        $this->tick();
        $this->reads->lists([Issues::task(1, with: ['due' => '2026-12-01T08:00:00Z']), Issues::task(2, with: ['due' => '2026-12-10T08:00:00Z'])]);

        self::assertSame([1, 2], $this->readyOf($this->planOf($this->tick())));
    }

    public function testAfterTheEligibilityLabelChangesEveryStoredRowIsJudgedByTheNewLabel(): void
    {
        $this->reads->lists([Issues::task(1, ['build-me']), Issues::task(2, ['intakt'])]);
        self::assertSame([2], $this->readyOf($this->planOf($this->tick(), 'intakt')));
        $this->reads->listsUnchanged();

        $items = $this->tick();

        self::assertSame([1], array_map(static fn (QueuedIssue $item): int => $item->number(), new IntakeRules('build-me')->itemsNeedingHistory($items->items ?? [])));
        self::assertSame([1], $this->readyOf($this->planOf($items, 'build-me')));
    }

    public function testAListingWithoutAValidatorRemovesTheStoredOne(): void
    {
        $this->reads->lists([Issues::task(1)]);
        $this->tick();
        $this->reads->lists([Issues::task(1)], null);

        $this->tick();

        self::assertNull($this->entities->find(IntakeValidator::class, 'alpha'));
        self::assertSame([null, 'W/"issues-1"'], $this->reads->validators);
    }

    private function tick(): ?OpenItems
    {
        return $this->tickOver($this->reads);
    }

    private function tickOver(IssueReads $reads): ?OpenItems
    {
        return $this->snapshots($reads)->current('alpha');
    }

    private function snapshots(IssueReads $reads): IntakeSnapshots
    {
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(LoggerInterface::class, $logger);

        return new IntakeSnapshots($this->entities, $this->locks, $reads, new IssueReferences(), $logger);
    }

    // Vouched for by a writer, as the pick will do before it plans, because only then does a row count as eligible.
    private function planOf(?OpenItems $items, string $label = 'intakt'): IntakePlan
    {
        self::assertInstanceOf(OpenItems::class, $items);
        $history = new IssueHistory('ana', array_map(static fn (string $name): LabelApplication => new LabelApplication($name, 'ana'), ['intakt', 'build-me', 'priority:urgent']));
        $vouched = array_map(static fn (QueuedIssue $item): QueuedIssue => $item->vouchedBy(Vouching::of('ana', $history, static fn (string $login): bool => true)), $items->items);

        return new IntakeRules($label)->planOf($vouched, $items->failures);
    }

    /**
     * @return list<int>
     */
    private function readyOf(IntakePlan $plan): array
    {
        return array_map(static fn (QueuedIssue $task): int => $task->number(), $plan->ready);
    }

    private function reasonOf(int $number, IntakePlan $plan): ?string
    {
        return array_find($plan->held, static fn (HeldIssue $held): bool => $held->issue->number() === $number)?->reason;
    }

    /**
     * @param list<string> $labels
     */
    private function page(int $first, int $count, array $labels = ['intakt']): string
    {
        return $this->pageOf($this->issues($first, $count, $labels));
    }

    /**
     * @param list<string> $labels
     *
     * @return list<array<string, mixed>>
     */
    private function issues(int $first, int $count, array $labels = ['intakt'], string $body = ''): array
    {
        $issues = [];
        for ($number = $first; $number < $first + $count; ++$number) {
            $issues[] = ['number' => $number, 'title' => 'Task '.$number, 'body' => $body, 'user' => ['login' => 'ana'], 'labels' => array_map(static fn (string $name): array => ['name' => $name], $labels), 'milestone' => null, 'updated_at' => '2026-11-10T10:00:00Z'];
        }

        return $issues;
    }

    /**
     * @param list<array<string, mixed>> $issues
     */
    private function pageOf(array $issues): string
    {
        return json_encode($issues, JSON_THROW_ON_ERROR);
    }
}
