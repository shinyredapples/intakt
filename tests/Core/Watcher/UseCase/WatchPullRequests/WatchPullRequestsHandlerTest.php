<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Budget\Domain\ItemFailureRecord;
use Intakt\Core\Budget\Domain\SharedHoldRecord;
use Intakt\Core\Budget\Infrastructure\DoctrineHolds;
use Intakt\Core\Budget\Infrastructure\DoctrineInstallWideFailures;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\StartReview;
use Intakt\Core\Review\Contract\StartReviewAtHead;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Watcher\Domain\AdmissionDecision;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\Domain\HeadFailure;
use Intakt\Core\Watcher\Domain\PendingHead;
use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Core\Watcher\UseCase\AdmitHeads\AdmitHeads;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\SeenListings;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\ChangedHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\DiscardedRounds;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\FailedHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\HeadBackOff;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\HeadDecisions;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\HeadLocks;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\HeadsWithoutRound;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\LookAtHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\PendingHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\RoundStarts;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\SettleHead;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\SettleHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\StaleVerdicts;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\StartedRounds;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchedProject;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchedProjects;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherEventNotRecorded;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherLedger;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchPullRequests;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchPullRequestsHandler;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Watcher\UseCase\AdmitHeads\RecordedChecks;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordedForge;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordingLogger;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;

#[Group('persistence')]
#[CoversClass(WatchPullRequestsHandler::class)]
#[CoversClass(ChangedHeads::class)]
#[CoversClass(LookAtHeads::class)]
#[CoversClass(HeadLocks::class)]
#[CoversClass(HeadDecisions::class)]
#[CoversClass(SettleHeads::class)]
#[CoversClass(SettleHead::class)]
#[CoversClass(HeadsWithoutRound::class)]
#[CoversClass(HeadBackOff::class)]
#[CoversClass(FailedHeads::class)]
#[CoversClass(HeadFailure::class)]
#[CoversClass(PendingHeads::class)]
#[CoversClass(PendingHead::class)]
#[CoversClass(StartedRounds::class)]
#[CoversClass(StartedRound::class)]
#[CoversClass(RoundStarts::class)]
#[CoversClass(StaleVerdicts::class)]
#[CoversClass(WatcherLedger::class)]
#[CoversClass(WatchedProjects::class)]
#[CoversClass(WatchedProject::class)]
#[UsesClass(WatchPullRequests::class)]
#[UsesClass(DoctrineInstallWideFailures::class)]
#[UsesClass(ItemFailureRecord::class)]
#[UsesClass(DoctrineHolds::class)]
#[UsesClass(SharedHoldRecord::class)]
#[UsesClass(SharedHold::class)]
#[UsesClass(FindChangedPullRequests::class)]
#[UsesClass(SeenListings::class)]
#[UsesClass(AdmitHeads::class)]
#[UsesClass(AdmissionDecision::class)]
#[UsesClass(HeadDecision::class)]
final class WatchPullRequestsHandlerTest extends KernelTestCase
{
    private const string PENDING = 'a';
    private const string GREEN = 'b';
    private const string RED = 'c';
    private const string NEXT_GREEN = 'e';
    private const string BASE = 'f';
    // No recording, so reading its CI fails on every try.
    private const string UNREADABLE = 'd';

    private EntityManagerInterface $entities;
    private RecordedForge $listing;
    private RecordedChecks $checks;
    private RecordingCommandBus $commands;
    private InMemoryLedger $ledger;
    private MockClock $clock;
    private StandingQueryBus $queries;
    private BeforeChangeLockStore $locks;
    private RecordingLogger $logger;
    private WatchPullRequestsHandler $handler;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->listing = new RecordedForge();
        $this->checks = new RecordedChecks([str_repeat(self::PENDING, 40) => 'pending', str_repeat(self::GREEN, 40) => 'green', str_repeat(self::RED, 40) => 'red', str_repeat(self::NEXT_GREEN, 40) => 'rerun']);
        $this->commands = new RecordingCommandBus();
        $this->ledger = new InMemoryLedger();
        $this->clock = new MockClock('2026-11-02T10:00:00+00:00');
        $recorded = new VerdictIdentity(new CommitRange(new CommitId(str_repeat(self::BASE, 40)), new CommitId(str_repeat(self::GREEN, 40))), ReviewTerms::of('Review it.', 'bin/check', $this->panel()));
        $this->queries = new StandingQueryBus($recorded, $entities);
        $this->locks = new BeforeChangeLockStore();
        $this->logger = new RecordingLogger();
        $this->handler = $this->handler();
    }

    public function testANewHeadWithPendingCiStartsNoRound(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertSame([], $this->commands->started);
        self::assertSame([LedgerEventKind::HeadPending], $this->ledger->kinds());
    }

    public function testAPendingHeadIsReadAgainOnceItsReadAgainTimeHasPassedEvenWhenTheListingIsUnchanged(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->tick();

        $this->clock->modify('+61 seconds');
        $this->tick();

        self::assertSame(1, $this->listing->fullReads);
        self::assertSame(1, $this->listing->conditionalAnswers);
        self::assertCount(2, $this->checks->asked);
    }

    public function testAPendingHeadIsNotReadAgainBeforeItsReadAgainTime(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->tick();

        $this->clock->modify('+59 seconds');
        $this->tick();

        self::assertCount(1, $this->checks->asked);
    }

    public function testAPendingHeadThatTurnedGreenStartsItsRoundAndIsNoLongerWaitedOn(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->tick();
        $this->listing->opens('alpha', RecordedForge::change('1', self::GREEN, '2026-11-02T10:00:30Z'));

        $this->tick();

        self::assertCount(1, $this->commands->started);
        self::assertSame(0, $this->entities->getRepository(PendingHead::class)->count([]));
    }

    public function testAGreenHeadStartsExactlyOneRoundOnTheHeadWhoseCiWasRead(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));

        $this->tick();

        $start = new StartReview('alpha', 'Review it.', new RequestedRound('7', null, new ChangeSource('repo', 'main')));
        self::assertEquals([new StartReviewAtHead($start, new CommitId(str_repeat(self::GREEN, 40)))], $this->commands->started);
        self::assertSame([LedgerEventKind::RoundQueued], $this->ledger->kinds());
    }

    public function testAGreenHeadSeenAgainOnASecondTickStartsNoSecondRound(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->tick();
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:30:00Z'));

        $this->tick();

        self::assertCount(1, $this->commands->started);
        self::assertSame(0, $this->entities->getRepository(PendingHead::class)->count([]));
    }

    public function testTwoTicksOnTheSameGreenHeadStartOneRoundWhenTheSecondRunsInsideTheFirstStart(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->commands->whileStarting = function (): void {
            $this->commands->whileStarting = null;
            $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:30:00Z'));
            $this->tick();
        };

        $this->tick();
        $this->tick();

        self::assertCount(1, $this->commands->started);
        self::assertSame([LedgerEventKind::RoundQueued], $this->ledger->kinds());
    }

    public function testAHeadThatAnotherTickSettledBeforeThisTickTookItsLockIsNotSettledAgain(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->locks->beforeChangeLock = function (): void {
            $this->listing->opens('alpha', RecordedForge::change('7', self::PENDING, '2026-11-02T09:30:00Z'));
            $this->tick();
        };

        $this->tick();

        self::assertSame([], $this->commands->started);
        self::assertSame([LedgerEventKind::HeadPending], $this->ledger->kinds());
    }

    public function testANewerHeadQueuedBeforeThisTickTookItsLockIsTheHeadThisTickSettles(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->locks->beforeChangeLock = function (): void {
            new PendingHeads($this->entities, $this->clock)->enqueue('alpha', [RecordedForge::change('7', self::GREEN, '2026-11-02T09:30:00Z')]);
        };

        $this->tick();

        self::assertCount(1, $this->commands->started);
        self::assertEquals(new CommitId(str_repeat(self::GREEN, 40)), $this->commands->started[0]->head);
    }

    public function testALockStoreThatFailsWhileAHeadLockIsTakenStartsTheFailureBackOff(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->locks->beforeChangeLock = static fn () => throw new LockStorageException('the lock store is down');

        try {
            $this->tick();
            self::fail('A lock store that fails must fail the tick.');
        } catch (LockAcquiringException) {
            // Expected: the tick fails, so the head stays due for the next one.
        }

        self::assertSame([SharedHold::FailureBackOff], new DoctrineHolds($this->entities, $this->clock)->sharedInEffect());
        self::assertSame([], $this->commands->started);
    }

    public function testALockStoreThatFailsWhileAHeadLockIsGivenBackStartsTheFailureBackOff(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->locks->deleteFailure = new LockStorageException('the lock store is down');

        try {
            $this->tick();
            self::fail('A lock store that fails must fail the tick.');
        } catch (LockReleasingException) {
            // Expected: the tick fails, and the lock lapses with its time to live.
        }

        self::assertSame([SharedHold::FailureBackOff], new DoctrineHolds($this->entities, $this->clock)->sharedInEffect());
    }

    public function testAHeadLockHeldByAnotherTickStartsNoFailureBackOff(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        // Not released on destruction, because the other tick still holds it when this tick tries.
        $this->locks->beforeChangeLock = fn (Key $key): bool => new LockFactory($this->locks)->createLock((string) $key, autoRelease: false)->acquire();

        $this->tick();

        self::assertSame([], new DoctrineHolds($this->entities, $this->clock)->sharedInEffect());
        self::assertSame([], $this->commands->started);
    }

    public function testAFailedStartIsTriedAgainOnceItsBackOffHasPassedEvenWhenTheListingIsUnchanged(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->commands->whileStarting = $this->failAlways();
        $this->tick();
        $this->commands->whileStarting = null;

        $this->clock->modify('+61 seconds');
        $this->tick();

        self::assertSame(1, $this->listing->conditionalAnswers);
        self::assertCount(2, $this->commands->started);
        self::assertSame([LedgerEventKind::HeadFailed, LedgerEventKind::RoundQueued], $this->ledger->kinds());
    }

    public function testAFailedStartIsNotTriedAgainBeforeItsBackOffHasPassed(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->commands->whileStarting = $this->failAlways();
        $this->tick();
        $this->commands->whileStarting = null;

        $this->clock->modify('+59 seconds');
        $this->tick();

        self::assertCount(1, $this->commands->started);
    }

    public function testAFailedStartLeavesNoRoundRecordedForItsHead(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->commands->whileStarting = $this->failAlways();

        $this->tick();

        self::assertSame(0, $this->entities->getRepository(StartedRound::class)->count([]));
        self::assertSame(1, $this->entities->getRepository(PendingHead::class)->count([]));
    }

    public function testAHeadThatCannotStartIsBackedOffAloneWhileTheNextHeadStartsItsRoundInTheSameTick(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'), RecordedForge::change('8', self::NEXT_GREEN, '2026-11-02T09:00:00Z'));
        $this->commands->whileStarting = static function (StartReviewAtHead $command): void {
            if ('7' === $command->start->round->change) {
                throw new RuntimeException('the start of 7 fails');
            }
        };

        $this->tick();

        self::assertSame([LedgerEventKind::HeadFailed, LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertSame(['7', '8'], array_column($this->ledger->payloads(), 'change'));
    }

    public function testAHeadWhoseFailedFlushClosesTheEntityManagerIsBackedOffAloneWhileTheNextHeadStartsItsRoundInTheSameTick(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'), RecordedForge::change('8', self::NEXT_GREEN, '2026-11-02T09:00:00Z'));
        $connection = $this->entities->getConnection();
        $this->commands->whileStarting = static function (StartReviewAtHead $command) use ($connection): void {
            if ('7' === $command->start->round->change) {
                // A row the start does not expect, so the flush of its own row breaks the key and closes the entity manager; typed booleans, because PostgreSQL refuses an integer in a boolean column.
                $connection->insert('watcher_started_round', [
                    'project' => 'alpha',
                    'change_ref' => '7',
                    'head' => $command->head->hash,
                    'round_id' => 'other',
                    'stale' => false,
                    'queued_recorded' => false,
                ], ['stale' => Types::BOOLEAN, 'queued_recorded' => Types::BOOLEAN]);
            }
        };

        $this->tick();

        self::assertSame([LedgerEventKind::HeadFailed, LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertSame(['7', '8'], array_column($this->ledger->payloads(), 'change'));
        $cause = $this->ledger->payloads()[0]['cause'];
        self::assertIsString($cause);
        self::assertStringStartsWith(UniqueConstraintViolationException::class.':', $cause);
    }

    public function testAHeadWhoseFailureMessageIsNotUtf8IsBackedOffAloneWhileTheNextHeadStartsItsRoundInTheSameTick(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'), RecordedForge::change('8', self::NEXT_GREEN, '2026-11-02T09:00:00Z'));
        $this->commands->whileStarting = static function (StartReviewAtHead $command): void {
            if ('7' === $command->start->round->change) {
                throw new RuntimeException("no such path: \xFF");
            }
        };

        $this->tick();

        self::assertSame([LedgerEventKind::HeadFailed, LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertSame('RuntimeException: no such path: ?', $this->ledger->payloads()[0]['cause']);
    }

    public function testAHeadWhoseCiCannotBeReadIsBackedOffAloneWhileTheNextHeadStartsItsRoundInTheSameTick(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('6', self::UNREADABLE, '2026-11-02T09:00:00Z'), RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertSame([LedgerEventKind::HeadFailed, LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertSame(['change' => '6', 'cause' => 'LogicException: Not a recording: ', 'failures' => 1, 'retry_at' => '2026-11-02T10:01:00+00:00'], array_intersect_key($this->ledger->payloads()[0], array_flip(['change', 'cause', 'failures', 'retry_at'])));
        self::assertSame('7', $this->ledger->payloads()[1]['change']);
    }

    public function testAHeadThatFailsIsLoggedAsAnErrorWithItsException(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('6', self::UNREADABLE, '2026-11-02T09:00:00Z'));

        $this->tick();

        $errors = array_values(array_filter($this->logger->logged, static fn (array $line): bool => isset($line[1]['exception'])));
        self::assertCount(1, $errors);
        self::assertSame(['A head failed; it backs off alone.', '6'], [$errors[0][0], $errors[0][1]['change']]);
    }

    public function testAHeadThatFailsOnEveryTryWaitsTwiceAsLongAfterEachFailure(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('6', self::UNREADABLE, '2026-11-02T09:00:00Z'));
        $this->tick();
        $this->clock->modify('+60 seconds');
        $this->tick();

        $this->clock->modify('+119 seconds');
        $this->tick();

        self::assertCount(2, $this->checks->asked);
        self::assertSame(['2026-11-02T10:01:00+00:00', '2026-11-02T10:03:00+00:00'], array_column($this->ledger->payloads(), 'retry_at'));
    }

    public function testAHeadThatFailsFourTimesInARowGoesToTheMaintainerAgentOnce(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('6', self::UNREADABLE, '2026-11-02T09:00:00Z'));

        $this->tickEvery('+20 minutes', 5);

        self::assertSame([...array_fill(0, 3, LedgerEventKind::HeadFailed), LedgerEventKind::HeadHandedOver, LedgerEventKind::HeadFailed], $this->ledger->kinds());
        self::assertSame(4, $this->ledger->payloads()[3]['failures']);
    }

    public function testAHeadThatKeepsFailingWaitsAtMostHalfAnHourForItsNextTry(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('6', self::UNREADABLE, '2026-11-02T09:00:00Z'));

        $this->tickEvery('+1 hour', 6);

        self::assertCount(6, $this->checks->asked);
        self::assertSame('2026-11-02T15:30:00+00:00', $this->ledger->payloads()[5]['retry_at']);
    }

    public function testAHandedOverHeadWhoseFaultClearsStartsItsRoundWithoutItsPullRequestMoving(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->commands->whileStarting = static function (): void {
            throw new RuntimeException('commit not fetched yet');
        };
        $this->tickEvery('+20 minutes', 4);
        $this->commands->whileStarting = null;

        $this->tickEvery('+30 minutes', 1);

        self::assertSame(LedgerEventKind::RoundQueued, array_last($this->ledger->kinds()));
    }

    public function testAHeadThatFailsThreeTimesInARowIsStillTried(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('6', self::UNREADABLE, '2026-11-02T09:00:00Z'));

        $this->tickEvery('+20 minutes', 3);

        self::assertNotContains(LedgerEventKind::HeadHandedOver, $this->ledger->kinds());
        self::assertSame(1, $this->entities->getRepository(PendingHead::class)->count([]));
    }

    public function testAHeadHandedOverIsTriedAgainOnceItsPullRequestMoves(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('6', self::UNREADABLE, '2026-11-02T09:00:00Z'));
        $this->tickEvery('+20 minutes', 4);
        $this->listing->opens('alpha', RecordedForge::change('6', self::GREEN, '2026-11-02T12:00:00Z'));

        $this->tick();

        self::assertCount(1, $this->commands->started);
    }

    public function testAFailureTheLedgerCannotRecordFailsTheTickAndLeavesTheHeadDue(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('6', self::UNREADABLE, '2026-11-02T09:00:00Z'));
        $this->useLedger(new InMemoryLedger(0));
        $this->tickExpectingFailure();
        $this->useLedger(new InMemoryLedger());

        $this->tick();

        self::assertCount(2, $this->checks->asked);
        self::assertSame([LedgerEventKind::HeadFailed], $this->ledger->kinds());
    }

    public function testAHeadThatMovesDuringARoundMakesThatRoundsVerdictsStaleAndStoresIt(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->move = new HeadMove(HeadMoveKind::Commits, ['src/Feature.php']);
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:05:00Z'));

        $this->tick();

        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::VerdictsStale, LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertSame('round-1', $this->queries->asked[0]->roundId);
        self::assertTrue($this->storedStale(self::GREEN));
    }

    public function testAStaleRoundIsRecordedOnceWhileTheMovedHeadIsReadAgain(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->move = new HeadMove(HeadMoveKind::Rewritten, []);
        $this->listing->opens('alpha', RecordedForge::change('7', self::PENDING, '2026-11-02T10:05:00Z'));
        $this->tick();

        $this->clock->modify('+61 seconds');
        $this->tick();

        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::HeadPending, LedgerEventKind::VerdictsStale, LedgerEventKind::HeadPending], $this->ledger->kinds());
    }

    public function testAHeadThatMovesWhileItsRoundIsStillStartingMakesThatRoundsVerdictsStaleOnTheNextTick(): void
    {
        $this->queries->move = new HeadMove(HeadMoveKind::Commits, ['src/Feature.php']);
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->commands->whileStarting = function (): void {
            $this->commands->whileStarting = null;
            $this->listing->opens('alpha', RecordedForge::change('7', self::PENDING, '2026-11-02T10:05:00Z'));
            $this->tick();
        };
        $this->tick();

        $this->tick();

        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::HeadPending, LedgerEventKind::VerdictsStale], $this->ledger->kinds());
        self::assertTrue($this->storedStale(self::GREEN));
    }

    public function testAHeadThatMovesOnlyInDocumentationLeavesTheVerdictsStanding(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->move = new HeadMove(HeadMoveKind::Commits, ['docs/feature.md']);
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:05:00Z'));

        $this->tick();

        self::assertNotContains(LedgerEventKind::VerdictsStale, $this->ledger->kinds());
        self::assertFalse($this->storedStale(self::GREEN));
    }

    public function testAMovedHeadWithPendingCiMakesTheVerdictsStaleButStartsNoNewRound(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->move = new HeadMove(HeadMoveKind::Rewritten, []);
        $this->listing->opens('alpha', RecordedForge::change('7', self::PENDING, '2026-11-02T10:05:00Z'));

        $this->tick();

        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::HeadPending, LedgerEventKind::VerdictsStale], $this->ledger->kinds());
        self::assertCount(1, $this->commands->started);
    }

    public function testAMovedHeadNotFetchedYetIsStillRecordedPendingAndWaitsItsInterval(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->fetched = false;
        $this->listing->opens('alpha', RecordedForge::change('7', self::PENDING, '2026-11-02T10:05:00Z'));
        $this->tick();

        $this->clock->modify('+59 seconds');
        $this->tick();

        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::HeadPending, LedgerEventKind::HeadFailed], $this->ledger->kinds());
        self::assertCount(2, $this->checks->asked);
    }

    public function testAMovedHeadNotFetchedYetIsStillRecordedRedAndStaysQueuedForItsNextTry(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->fetched = false;
        $this->listing->opens('alpha', RecordedForge::change('7', self::RED, '2026-11-02T10:05:00Z'));

        $this->tick();

        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::HeadRed, LedgerEventKind::HeadFailed], $this->ledger->kinds());
        self::assertSame(1, $this->entities->getRepository(PendingHead::class)->count([]));
    }

    public function testARedMovedHeadIsCheckedAgainForStaleVerdictsOnceItsCommitArrives(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->fetched = false;
        $this->queries->move = new HeadMove(HeadMoveKind::Rewritten, []);
        $this->listing->opens('alpha', RecordedForge::change('7', self::RED, '2026-11-02T10:05:00Z'));
        $this->tick();
        $this->queries->fetched = true;

        $this->clock->modify('+61 seconds');
        $this->tick();

        self::assertTrue($this->storedStale(self::GREEN));
        self::assertSame(0, $this->entities->getRepository(PendingHead::class)->count([]));
    }

    public function testARedHeadWhoseStaleCheckSucceedsIsNoLongerWaitedOn(): void
    {
        $this->startRoundOnGreenHead();
        $this->listing->opens('alpha', RecordedForge::change('7', self::RED, '2026-11-02T10:05:00Z'));

        $this->tick();
        $this->tick();

        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::HeadRed, LedgerEventKind::VerdictsStale], $this->ledger->kinds());
        self::assertSame(0, $this->entities->getRepository(PendingHead::class)->count([]));
    }

    public function testAStaleLineTheLedgerCouldNotTakeIsRecordedByALaterTick(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->move = new HeadMove(HeadMoveKind::Rewritten, []);
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:05:00Z'));
        $this->useLedger(new InMemoryLedger(0));
        $this->tickExpectingFailure();
        $this->useLedger(new InMemoryLedger());

        $this->tick();

        self::assertSame([LedgerEventKind::VerdictsStale, LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertTrue($this->storedStale(self::GREEN));
    }

    public function testAStaleRoundWhoseLineWasRecordedIsNotRecordedAgainByALaterMove(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->move = new HeadMove(HeadMoveKind::Rewritten, []);
        $this->listing->opens('alpha', RecordedForge::change('7', self::PENDING, '2026-11-02T10:05:00Z'));
        $this->tick();
        $this->listing->opens('alpha', RecordedForge::change('7', self::RED, '2026-11-02T10:06:00Z'));

        $this->tick();

        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::HeadPending, LedgerEventKind::VerdictsStale, LedgerEventKind::HeadRed], $this->ledger->kinds());
    }

    public function testAPullRequestClosedWhileItsHeadWaitsIsNoLongerReadAgain(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->tick();
        $this->listing->opens('alpha', RecordedForge::change('2', self::GREEN, '2026-11-02T09:30:00Z'));
        $this->clock->modify('+61 seconds');

        $this->tick();
        $this->clock->modify('+61 seconds');
        $this->tick();

        self::assertCount(2, $this->checks->asked);
        self::assertSame(0, $this->entities->getRepository(PendingHead::class)->count([]));
    }

    public function testAFailedHeadWhosePullRequestClosesIsForgottenInTheLedger(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('6', self::UNREADABLE, '2026-11-02T09:00:00Z'));
        $this->tick();
        $this->listing->opens('alpha', RecordedForge::change('2', self::GREEN, '2026-11-02T09:30:00Z'));

        $this->tick();

        self::assertSame([LedgerEventKind::HeadFailed, LedgerEventKind::HeadForgotten, LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertSame(['project' => 'alpha', 'change' => '6', 'head' => str_repeat(self::UNREADABLE, 40)], $this->ledger->payloads()[1]);
        self::assertSame(0, $this->entities->getRepository(PendingHead::class)->count(['change' => '6']));
    }

    public function testAForgottenHeadTheLedgerCannotRecordKeepsItsRowAndTheListingForTheNextTick(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->tick();
        $this->listing->opens('alpha');
        $this->useLedger(new InMemoryLedger(0));
        $this->tickExpectingFailure();
        $this->useLedger(new InMemoryLedger());

        $this->tick();

        self::assertSame([LedgerEventKind::HeadForgotten], $this->ledger->kinds());
        self::assertSame(0, $this->entities->getRepository(PendingHead::class)->count([]));
    }

    public function testAPullRequestStillOpenKeepsItsWaitingHeadWhenAnotherPullRequestChanges(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->tick();
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'), RecordedForge::change('2', self::GREEN, '2026-11-02T09:30:00Z'));

        $this->tick();

        self::assertSame(1, $this->entities->getRepository(PendingHead::class)->count(['change' => '1']));
    }

    public function testARoundWhoseLedgerLineFailedIsRecordedByALaterTickWithoutASecondRound(): void
    {
        $this->useLedger(new InMemoryLedger(0));
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->tickExpectingFailure();
        $this->useLedger(new InMemoryLedger());

        $this->tick();

        self::assertCount(1, $this->commands->started);
        self::assertSame([LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertSame('7', $this->ledger->payloads()[0]['change']);
    }

    public function testARoundsLedgerLineIsRecordedOnlyOnceOverLaterTicks(): void
    {
        $this->startRoundOnGreenHead();

        $this->tick();
        $this->tick();

        self::assertSame([LedgerEventKind::RoundQueued], $this->ledger->kinds());
    }

    public function testATickOverOnePendingAndOneGreenPullRequestRecordsEachInTheLedger(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'), RecordedForge::change('2', self::GREEN, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::HeadPending], $this->ledger->kinds());
        self::assertSame('2', $this->ledger->payloads()[0]['change']);
        self::assertSame('1', $this->ledger->payloads()[1]['change']);
    }

    public function testAHeadThatReturnsToAnEarlierHeadWhoseRoundIsStaleStartsAFreshRound(): void
    {
        $this->moveFromGreenToNextGreenAndBack();

        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::VerdictsStale, LedgerEventKind::RoundQueued, LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertCount(3, $this->commands->started);
        self::assertFalse($this->storedStale(self::GREEN));
    }

    public function testARoundRestartedOnAReturningHeadIsReportedStaleAgainWhenTheHeadMovesAwayOnceMore(): void
    {
        $this->moveFromGreenToNextGreenAndBack();
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:15:00Z'));

        $this->tick();

        self::assertSame(LedgerEventKind::VerdictsStale, $this->ledger->kinds()[4]);
        self::assertTrue($this->storedStale(self::GREEN));
    }

    public function testAHeadThatReturnsToAnEarlierHeadWhoseRoundStandsStartsNoSecondRound(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->move = new HeadMove(HeadMoveKind::Commits, ['docs/feature.md']);
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:05:00Z'));
        $this->tick();
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:10:00Z'));

        $this->tick();

        self::assertCount(2, $this->commands->started);
    }

    private function moveFromGreenToNextGreenAndBack(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->move = new HeadMove(HeadMoveKind::Commits, ['src/Feature.php']);
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:05:00Z'));
        $this->tick();
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:10:00Z'));

        $this->tick();
    }

    private function startRoundOnGreenHead(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->tick();
    }

    private function useLedger(InMemoryLedger $ledger): void
    {
        $this->ledger = $ledger;
        $this->handler = $this->handler();
    }

    private function tick(): void
    {
        ($this->handler)(new WatchPullRequests('alpha'));
    }

    private function tickEvery(string $interval, int $ticks): void
    {
        foreach (range(1, $ticks) as $ignored) {
            $this->tick();
            $this->clock->modify($interval);
        }
    }

    private function tickExpectingFailure(): void
    {
        try {
            $this->tick();
            self::fail('The line the ledger refused was swallowed.');
        } catch (WatcherEventNotRecorded) {
            // Expected: a line the ledger cannot take fails the tick, so the step is tried again.
        }
    }

    private function failAlways(): Closure
    {
        return static fn () => throw new RuntimeException('the bus is down');
    }

    // Read with plain SQL after a clear, because only what reached the database counts.
    private function storedStale(string $head): bool
    {
        $this->entities->clear();

        return (bool) $this->entities->getConnection()->fetchOne('SELECT stale FROM watcher_started_round WHERE head = ?', [str_repeat($head, 40)]);
    }

    private function handler(): WatchPullRequestsHandler
    {
        $locks = new LockFactory($this->locks);
        $ledger = new WatcherLedger($this->ledger);
        $logger = $this->logger;
        $pending = new PendingHeads($this->entities, $this->clock);
        $rounds = new StartedRounds($this->entities, $pending, new DiscardedRounds($this->commands, $logger));
        $find = new FindChangedPullRequests($this->listing, new SeenListings($this->entities, $locks), $logger);
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $redactor = self::createStub(Redactor::class);
        $redactor->method('redact')->willReturnArgument(0);
        $installWide = new DoctrineInstallWideFailures($this->entities, new DoctrineHolds($this->entities, $this->clock), $this->clock, $redactor, $logger);
        $backOff = new HeadBackOff(new FailedHeads($pending, $ledger, $this->clock, $redactor, $this->entities->getConnection(), $installWide), $doctrine, $logger);
        $decisions = new HeadDecisions(new AdmitHeads($this->checks, new AdmissionDecision(), $this->clock, $logger), new AdmissionDecision(), $backOff);
        $starts = new RoundStarts($this->commands, $rounds, $ledger);
        $settle = new SettleHeads(new SettleHead(new StaleVerdicts($this->queries, $rounds, $ledger), new HeadsWithoutRound($ledger, $pending), $starts), $starts, $backOff);
        $projects = new WatchedProjects(['alpha' => ['repository' => 'repo', 'base' => 'main', 'task' => 'Review it.']]);

        return new WatchPullRequestsHandler($projects, new ChangedHeads($find, $pending, $ledger, $this->entities->getConnection()), new LookAtHeads(new HeadLocks($pending, $locks, $installWide), $decisions, $settle));
    }

    private function panel(): Panel
    {
        return new Panel([new PanelReviewer(new ReviewerRole('core', 'Review the change.'), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10)], QuorumPolicy::All);
    }
}
