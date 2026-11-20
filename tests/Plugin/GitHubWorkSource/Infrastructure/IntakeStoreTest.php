<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource\Infrastructure;

use DateTimeZone;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\TaskFailure;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\IntakeStore;
use Intakt\Plugin\WorkSource\Contract\TaskNotQueued;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Plugin\GitHubWorkSource\Issues;
use Intakt\Tests\Support\KernelTestCase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use WeakReference;

#[Group('persistence')]
#[CoversClass(IntakeStore::class)]
#[CoversClass(StoredIssue::class)]
#[CoversClass(IntakeValidator::class)]
#[CoversClass(TaskFailure::class)]
#[CoversClass(Vouching::class)]
#[UsesClass(ItemReading::class)]
#[UsesClass(QueuedIssue::class)]
#[UsesClass(IssueReferences::class)]
#[UsesClass(IntakeRules::class)]
#[UsesClass(TaskNotQueued::class)]
final class IntakeStoreTest extends KernelTestCase
{
    private const array WRITERS = ['ana'];

    private EntityManagerInterface $entities;
    private IntakeStore $store;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->store = new IntakeStore($entities);
    }

    public function testAStoredIssueIsReadBackAsTheItemItWasStoredFrom(): void
    {
        $listed = Issues::task(7, ['intakt', 'priority:high'], ['body' => "Waits for #3\nWaits for whatever", 'due' => '2026-12-01T09:30:00Z', 'updatedAt' => '2026-11-10T10:00:00Z']);
        $this->store->record('alpha', [$this->vouched($listed)], null);
        $this->entities->clear();

        $queued = array_first($this->store->issuesOf('alpha'))?->queued();

        self::assertInstanceOf(QueuedIssue::class, $queued);
        self::assertSame(7, $queued->number());
        self::assertFalse($queued->isPullRequest());
        self::assertTrue($queued->isTrusted());
        self::assertTrue($queued->hasVouchedLabel('intakt'));
        self::assertSame('high', $queued->priority()->value);
        self::assertSame([3], $queued->waitsFor());
        self::assertSame(['Waits for whatever'], $queued->unreadableWaits());
        self::assertSame('2026-12-01 09:30:00', $queued->milestoneDue()?->format('Y-m-d H:i:s'));
    }

    public function testAnItemWhoseHistoryWasNotReadIsReadBackUntrusted(): void
    {
        $this->store->record('alpha', [$this->stored(Issues::task(7))], null);

        $queued = array_first($this->store->issuesOf('alpha'))?->queued();

        self::assertInstanceOf(QueuedIssue::class, $queued);
        self::assertFalse($queued->isTrusted());
        self::assertFalse($queued->hasVouchedLabel('intakt'));
    }

    public function testAPullRequestIsReadBackWithTheTasksItCloses(): void
    {
        $this->store->record('alpha', [$this->stored(Issues::pullRequest(12, 'Closes #7'))], null);

        $queued = array_first($this->store->issuesOf('alpha'))?->queued();

        self::assertInstanceOf(QueuedIssue::class, $queued);
        self::assertTrue($queued->isPullRequest());
        self::assertSame([7], $queued->closedBy());
    }

    public function testTheUpdateTimeIsStoredInUtcWithItsFractionOfASecond(): void
    {
        $listed = Issues::task(7);
        $later = new ListedIssue(7, $listed->title, '', $listed->labels, $listed->author, $listed->updatedAt->setTimezone(new DateTimeZone('Europe/Berlin'))->modify('+250 milliseconds'), null, false);

        $this->store->record('alpha', [$this->stored($later)], null);

        self::assertSame('2026-11-10 10:00:00.250000', $this->entities->getConnection()->fetchOne('SELECT updated_at FROM work_source_issue WHERE number = 7'));
    }

    public function testAnIssueRecordedAgainReplacesItsRowInsteadOfAddingOne(): void
    {
        $this->store->record('alpha', [$this->vouched(Issues::task(7, ['intakt'], ['body' => 'Waits for #3']))], null);

        $this->store->record('alpha', [$this->stored(Issues::task(7, ['intakt'], ['body' => 'Waits for #4']))], null);

        $issues = $this->store->issuesOf('alpha');
        self::assertCount(1, $issues);
        self::assertSame([4], $issues[0]->queued()->waitsFor());
        self::assertFalse($issues[0]->queued()->isTrusted());
    }

    public function testARowReadBackAndRecordedAgainKeepsWhatItsHistorySaid(): void
    {
        $this->store->record('alpha', [$this->vouched(Issues::task(7))], null);

        $this->store->record('alpha', $this->store->issuesOf('alpha'), null);

        self::assertTrue(array_first($this->store->issuesOf('alpha'))?->queued()->isTrusted());
    }

    public function testAnIssueTheListingNoLongerShowsLosesItsRow(): void
    {
        $this->store->record('alpha', [$this->stored(Issues::task(7)), $this->stored(Issues::task(8))], null);

        $this->store->record('alpha', [$this->stored(Issues::task(8))], null);

        self::assertSame([8], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
    }

    public function testTheRowsOfAnotherProjectAreLeftAlone(): void
    {
        $this->store->record('beta', [$this->stored(Issues::task(7), 'beta')], null);

        $this->store->record('alpha', [], null);

        self::assertCount(1, $this->store->issuesOf('beta'));
    }

    public function testAnIssueOfAnotherProjectIsRefusedAndNothingIsWritten(): void
    {
        $this->store->record('alpha', [$this->stored(Issues::task(7))], null);

        try {
            $this->store->record('alpha', [$this->stored(Issues::task(8), 'beta')], 'W/"a"');
            self::fail('An issue of another project was recorded.');
        } catch (InvalidArgumentException) {
        }

        self::assertSame([7], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
        self::assertNull($this->store->validatorOf('alpha'));
    }

    public function testAListingWhoseWriteFailedLeavesNoRemovalForALaterFlush(): void
    {
        $connection = $this->entities->getConnection();
        $this->store->record('alpha', [$this->stored(Issues::task(7))], 'W/"a"');
        $connection->executeStatement('ALTER TABLE work_source_validator RENAME TO hidden_validator');
        try {
            $this->store->record('alpha', [], 'W/"b"');
            self::fail('The validator could be read without its table.');
        } catch (TableNotFoundException) {
        } finally {
            $connection->executeStatement('ALTER TABLE hidden_validator RENAME TO work_source_validator');
        }

        $this->entities->flush();

        self::assertSame([7], array_map(static fn (StoredIssue $issue): int => $issue->number(), $this->store->issuesOf('alpha')));
    }

    public function testRecordingAListingLeavesAnotherCallersPendingWorkUnwritten(): void
    {
        $this->entities->persist(new IntakeValidator('beta', 'W/"pending"'));

        $this->store->record('alpha', [], 'W/"a"');

        self::assertSame(['alpha'], $this->entities->getConnection()->fetchFirstColumn('SELECT project FROM work_source_validator ORDER BY project'));
    }

    public function testTheValidatorIsStoredReplacedAndDroppedWhenTheForgeSendsNone(): void
    {
        $this->store->record('alpha', [], 'W/"a"');
        $first = $this->store->validatorOf('alpha');
        $this->store->record('alpha', [], 'W/"b"');
        $second = $this->store->validatorOf('alpha');

        $this->store->record('alpha', [], null);

        self::assertSame(['W/"a"', 'W/"b"', null], [$first, $second, $this->store->validatorOf('alpha')]);
    }

    public function testARowStoredByAnotherWorkerSinceIsWhatIsReadBack(): void
    {
        $this->store->record('alpha', [$this->stored(Issues::task(7, ['intakt'], ['body' => 'Waits for #3']))], null);
        self::assertInstanceOf(StoredIssue::class, $this->entities->find(StoredIssue::class, ['project' => 'alpha', 'number' => 7]));
        $this->entities->getConnection()->executeStatement("UPDATE work_source_issue SET waits_for = '[5]' WHERE number = 7");

        self::assertSame([5], array_first($this->store->issuesOf('alpha'))?->queued()->waitsFor());
    }

    public function testEveryCallReleasesItsEntityManagerSoRepeatedTicksKeepNoRowsInMemory(): void
    {
        $this->store->record('alpha', [$this->stored(Issues::task(7))], 'W/"a"');
        $this->store->failForGood('alpha', 7, 'reason');
        $listener = new class {
            /** @var array<int, WeakReference<object>> */
            public array $managers = [];

            public function postLoad(PostLoadEventArgs $event): void
            {
                $manager = $event->getObjectManager();
                $this->managers[spl_object_id($manager)] = WeakReference::create($manager);
            }
        };
        $this->entities->getEventManager()->addEventListener([Events::postLoad], $listener);

        for ($tick = 0; $tick < 50; ++$tick) {
            $this->store->issuesOf('alpha');
            $this->store->failuresOf('alpha');
            $this->store->record('alpha', $this->store->issuesOf('alpha'), 'W/"a"');
        }

        $this->entities->getEventManager()->removeEventListener([Events::postLoad], $listener);
        gc_collect_cycles();

        self::assertNotSame([], $listener->managers);
        self::assertSame(0, count(array_filter($listener->managers, static fn (WeakReference $manager): bool => null !== $manager->get())), 'Entity managers of finished calls are still referenced.');
    }

    public function testTheFailedForGoodReasonsOfAnotherProjectAreLeftOut(): void
    {
        $this->store->record('alpha', [$this->stored(Issues::task(7))], null);
        $this->store->record('beta', [$this->stored(Issues::task(9), 'beta')], null);
        $this->store->failForGood('alpha', 7, 'first');
        $this->store->failForGood('beta', 9, 'other');

        self::assertSame([7 => 'first'], $this->store->failuresOf('alpha'));
    }

    public function testRemovingTheRowOfAClosedTaskKeepsItsFailedForGoodReasonSoItsDependentsStayHeld(): void
    {
        $this->store->record('alpha', [$this->vouched(Issues::task(7)), $this->vouched(Issues::task(8, ['intakt'], ['body' => 'Waits for #7']))], null);
        $this->store->failForGood('alpha', 7, 'the build ran out of attempts');

        $this->store->record('alpha', [$this->vouched(Issues::task(8, ['intakt'], ['body' => 'Waits for #7']))], null);

        $failures = $this->store->failuresOf('alpha');
        $plan = new IntakeRules('intakt')->planOf(array_map(static fn (StoredIssue $issue): QueuedIssue => $issue->queued(), $this->store->issuesOf('alpha')), $failures);
        self::assertSame([7 => 'the build ran out of attempts'], $failures);
        self::assertSame([8], array_map(static fn (StoredIssue $issue): int => $issue->queued()->number(), $this->store->issuesOf('alpha')));
        self::assertSame([], $plan->ready);
        self::assertSame(['depends on #7, which failed for good: the build ran out of attempts'], array_map(static fn ($held): string => $held->reason, $plan->held));
    }

    public function testFailingATaskForGoodAgainReplacesItsReason(): void
    {
        $this->store->record('alpha', [$this->stored(Issues::task(7))], null);
        $this->store->failForGood('alpha', 7, 'first');

        $this->store->failForGood('alpha', 7, 'second');

        self::assertSame([7 => 'second'], $this->store->failuresOf('alpha'));
    }

    public function testATaskTheStoredRowsDoNotShowCannotFailForGood(): void
    {
        $this->store->record('beta', [$this->stored(Issues::task(7), 'beta')], null);

        $this->expectException(TaskNotQueued::class);

        $this->store->failForGood('alpha', 7, 'reason');
    }

    public function testAnEntityManagerWhoseEventsCannotBeSharedIsRefused(): void
    {
        $this->expectException(LogicException::class);

        new IntakeStore(self::createStub(EntityManagerInterface::class))->issuesOf('alpha');
    }

    private function stored(ListedIssue $listed, string $project = 'alpha'): StoredIssue
    {
        $references = new IssueReferences();

        return StoredIssue::of($project, new ItemReading($listed, $references->waitsFor($listed->body), $references->unreadableWaits($listed->body), $references->closedBy($listed->body)));
    }

    private function vouched(ListedIssue $listed): StoredIssue
    {
        $issue = $this->stored($listed);
        $history = new IssueHistory($listed->author, array_map(static fn (string $label): LabelApplication => new LabelApplication($label, 'ana'), $listed->labels));
        $issue->vouch(Vouching::of($listed->author, $history, static fn (string $account): bool => in_array($account, self::WRITERS, true)));

        return $issue;
    }
}
