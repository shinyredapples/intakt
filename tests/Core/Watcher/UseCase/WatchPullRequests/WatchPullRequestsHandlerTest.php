<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Contract\InstallWideFailures;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Budget\Domain\FullDisk;
use Intakt\Core\Budget\Domain\ItemFailureRecord;
use Intakt\Core\Budget\Domain\SharedHoldRecord;
use Intakt\Core\Budget\Infrastructure\DoctrineHolds;
use Intakt\Core\Budget\Infrastructure\DoctrineInstallWideFailures;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Ledger\Domain\EventRecord;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use Intakt\Core\Ledger\Infrastructure\UtcDateTimeImmutableType;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\BootAt;
use Intakt\Core\Review\Contract\BootHarness;
use Intakt\Core\Review\Contract\BootHarnessAtMain;
use Intakt\Core\Review\Contract\BootOutcome;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\MainCommit;
use Intakt\Core\Review\Contract\MarkVerdictsStale;
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
use Intakt\Core\Watcher\Domain\BootKey;
use Intakt\Core\Watcher\Domain\BootRecord;
use Intakt\Core\Watcher\Domain\FailedBoot;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\Domain\HeadFailure;
use Intakt\Core\Watcher\Domain\PendingHead;
use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Core\Watcher\UseCase\AdmitHeads\AdmitHeads;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\SeenListings;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\BootGate;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\BootResults;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\ChangedHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\ChangesHandedToIntakt;
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
use Intakt\Core\Watcher\UseCase\WatchPullRequests\VerdictsWithdrawalRefused;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchedProject;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchedProjects;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherEventNotRecorded;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherLedger;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchPullRequests;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchPullRequestsHandler;
use Intakt\Plugin\Forge\Contract\AuthorTrust;
use Intakt\Plugin\Forge\Contract\ChangeAuthorship;
use Intakt\Plugin\Forge\Contract\ChangeLabels;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Watcher\UseCase\AdmitHeads\RecordedChecks;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordedForge;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordingLogger;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use LogicException;
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
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

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
#[CoversClass(VerdictsWithdrawalRefused::class)]
#[CoversClass(WatcherLedger::class)]
#[CoversClass(ChangesHandedToIntakt::class)]
#[CoversClass(WatchedProjects::class)]
#[CoversClass(WatchedProject::class)]
#[UsesClass(WatchPullRequests::class)]
#[UsesClass(DoctrineInstallWideFailures::class)]
#[UsesClass(ItemFailureRecord::class)]
#[UsesClass(FullDisk::class)]
#[UsesClass(DoctrineHolds::class)]
#[UsesClass(SharedHoldRecord::class)]
#[UsesClass(SharedHold::class)]
#[UsesClass(FindChangedPullRequests::class)]
#[UsesClass(SeenListings::class)]
#[UsesClass(AdmitHeads::class)]
#[UsesClass(AdmissionDecision::class)]
#[UsesClass(HeadDecision::class)]
#[UsesClass(BootGate::class)]
#[UsesClass(BootResults::class)]
#[UsesClass(BootKey::class)]
#[UsesClass(BootRecord::class)]
#[UsesClass(FailedBoot::class)]
#[UsesClass(BootAt::class)]
#[UsesClass(BootHarness::class)]
#[UsesClass(BootHarnessAtMain::class)]
#[UsesClass(BootOutcome::class)]
#[UsesClass(MainCommit::class)]
#[UsesClass(MarkVerdictsStale::class)]
#[UsesClass(Labels::class)]
#[UsesClass(DoctrineLedger::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(EventRecord::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(UtcDateTimeImmutableType::class)]
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
    private bool $authorTrusted = true;
    private bool $trustCheckDown = false;
    private Labels $steering;
    // Read from its own ledger, because the in-memory one reads nothing back.
    private ?Ledger $openings = null;
    private ?ChangeAuthorship $authorship = null;

    public function testANewHeadWithPendingCiStartsNoRound(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertSame([], $this->commands->started);
        self::assertSame([LedgerEventKind::HeadPending], $this->ledger->kinds());
    }

    public function testATickRecordsTheListedPullRequestIntaktOpenedAsHandedToIntaktBeforeItsHeadDecision(): void
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $this->openings = new DoctrineLedger($doctrine, $this->clock);
        self::assertTrue($this->openings->append(new LedgerEvent(LedgerEventKind::ChangeOpened, '{"project":"alpha","branch":"feat\/1","head":"'.str_repeat(self::PENDING, 40).'","change":"1","opening":"created"}'))->succeeded());
        $this->handler = $this->handler();
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'), RecordedForge::change('2', self::PENDING, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertSame([LedgerEventKind::ChangeHandedToIntakt, LedgerEventKind::HandOverPublicationQueued, LedgerEventKind::ChangeNotHandedToIntakt, LedgerEventKind::HeadPending, LedgerEventKind::HeadPending], $this->ledger->kinds());
        self::assertSame(['1', '1', '2'], array_column(array_slice($this->ledger->payloads(), 0, 3), 'change'));
    }

    public function testAPullRequestListedBeforeItsOpeningRecordIsRecordedAsHandedToIntaktOnAnUnchangedListing(): void
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $openings = new DoctrineLedger($doctrine, $this->clock);
        $this->openings = $openings;
        $this->handler = $this->handler();
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->tick();
        self::assertTrue($openings->append(new LedgerEvent(LedgerEventKind::ChangeOpened, '{"project":"alpha","branch":"feat\/1","head":"'.str_repeat(self::PENDING, 40).'","change":"1","opening":"created"}'))->succeeded());

        $this->tick();

        self::assertSame(1, $this->listing->conditionalAnswers);
        self::assertSame([LedgerEventKind::ChangeNotHandedToIntakt, LedgerEventKind::HeadPending, LedgerEventKind::ChangeHandedToIntakt, LedgerEventKind::HandOverPublicationQueued], $this->ledger->kinds());
    }

    public function testOneUnreadableAuthorDoesNotKeepAnUnrelatedHeadFromBeingQueued(): void
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $this->openings = new DoctrineLedger($doctrine, $this->clock);
        self::assertTrue($this->openings->append(new LedgerEvent(LedgerEventKind::ChangeOpened, '{"project":"alpha","change":"1"}'))->succeeded());
        $authorship = self::createStub(ChangeAuthorship::class);
        $authorship->method('isCreatedByIntakt')->willThrowException(new ForgeReadFailed('Author unavailable'));
        $this->authorship = $authorship;
        $this->handler = $this->handler();
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'), RecordedForge::change('2', self::PENDING, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertSame([LedgerEventKind::HeadPending, LedgerEventKind::HeadPending], $this->ledger->kinds());
        self::assertSame(['1', '2'], array_column($this->ledger->payloads(), 'change'));
    }

    public function testATickThatOverlapsAnotherWhileItReadsAnAuthorRecordsNoSecondHandOver(): void
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $this->openings = new DoctrineLedger($doctrine, $this->clock);
        self::assertTrue($this->openings->append(new LedgerEvent(LedgerEventKind::ChangeOpened, '{"project":"alpha","change":"1"}'))->succeeded());
        $overlapped = false;
        $authorship = self::createStub(ChangeAuthorship::class);
        $authorship->method('isCreatedByIntakt')->willReturnCallback(function () use (&$overlapped): bool {
            if (!$overlapped) {
                $overlapped = true;
                $this->tick();
            }

            return true;
        });
        $this->authorship = $authorship;
        $this->handler = $this->handler();
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertSame([LedgerEventKind::ChangeHandedToIntakt, LedgerEventKind::HandOverPublicationQueued, LedgerEventKind::HeadPending], $this->ledger->kinds());
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

    public function testAPendingHeadIsReadAgainWhileTheForgeHoldsTheListingBack(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->tick();

        $this->clock->modify('+61 seconds');
        $this->listing->limitedUntil = $this->clock->now()->modify('+30 minutes');
        $this->tick();

        self::assertCount(2, $this->checks->asked);
    }

    public function testAForgePauseOverSeveralTicksCountsNoHeadFailureAndHandsTheHeadOverToNoOne(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->tick();
        $paused = $this->clock->now()->modify('+2 hours');
        $this->listing->limitedUntil = $paused;
        $this->checks->pausedUntil = $paused;

        foreach (range(1, 5) as $ignored) {
            $this->clock->modify('+20 minutes');
            $this->tick();
        }

        self::assertSame([LedgerEventKind::HeadPending], $this->ledger->kinds());
        self::assertSame(0, new PendingHeads($this->entities, $this->clock)->failuresOf('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z')));
    }

    public function testAForgePauseOverTwentyTicksAMinuteApartHandsTheHeadOverToNoOne(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->tick();
        $paused = $this->clock->now()->modify('+1 hour');
        $this->listing->limitedUntil = $paused;
        $this->checks->pausedUntil = $paused;

        foreach (range(1, 20) as $ignored) {
            $this->clock->modify('+1 minute');
            $this->tick();
        }

        self::assertNotContains(LedgerEventKind::HeadHandedOver, $this->ledger->kinds());
        self::assertNotContains(LedgerEventKind::HeadFailed, $this->ledger->kinds());
    }

    public function testAHeadWhoseCiReadAForgePauseRefusedIsReadAgainOnceThePauseHasEndedAndNotBefore(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->tick();
        $this->clock->modify('+61 seconds');
        // The listing goes on, because the pause starts between the listing and the CI reads of this tick.
        $this->checks->pausedUntil = $this->clock->now()->modify('+30 minutes');
        $this->tick();

        $this->clock->modify('+29 minutes');
        $this->tick();
        $readBeforeTheReset = count($this->checks->asked);
        $this->clock->modify('+61 seconds');
        $this->checks->pausedUntil = null;
        $this->tick();

        self::assertSame(1, $readBeforeTheReset);
        self::assertCount(2, $this->checks->asked);
        self::assertSame([LedgerEventKind::HeadPending, LedgerEventKind::HeadPending], $this->ledger->kinds());
    }

    public function testAForgePauseOnTwoHeadsOfOneTickStartsNoFailureBackOff(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'), RecordedForge::change('2', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->checks->pausedUntil = $this->clock->now()->modify('+30 minutes');

        $this->tick();

        self::assertSame([], new DoctrineHolds($this->entities, $this->clock)->sharedInEffect());
        self::assertSame([], $this->ledger->kinds());
    }

    public function testAHeadWhoseForgePauseCannotBeStoredIsBackedOffAloneWhileTheNextHeadOfTheTickStartsItsRound(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'), RecordedForge::change('2', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->checks->pausedUntil = $this->clock->now()->modify('+30 minutes');
        $this->checks->pausedHead = str_repeat(self::PENDING, 40);
        $this->handler = $this->handler(waits: new PendingHeads(new UnwritableQueries($this->entities), $this->clock));

        $this->tick();

        self::assertSame([LedgerEventKind::HeadFailed, LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertSame(['1', '2'], array_column($this->ledger->payloads(), 'change'));
        self::assertSame(1, new PendingHeads($this->entities, $this->clock)->failuresOf('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z')));
        $failed = array_find($this->logger->logged, static fn (array $line): bool => 'A head failed; it backs off alone.' === $line[0]);
        self::assertIsArray($failed);
        $exception = $failed[1]['exception'] ?? null;
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('the pending head cannot be written', $exception->getMessage());
    }

    public function testAHeadWhoseForgePauseAndBackOffCannotBeStoredStaysDueWhileTheNextHeadOfTheTickStartsItsRound(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'), RecordedForge::change('2', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->checks->pausedUntil = $this->clock->now()->modify('+30 minutes');
        $this->checks->pausedHead = str_repeat(self::PENDING, 40);
        $unwritable = new PendingHeads(new UnwritableQueries($this->entities), $this->clock);
        $this->handler = $this->handler(
            waits: $unwritable,
            backOffs: $unwritable,
        );

        $this->tick();

        self::assertSame([LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertSame(['2'], array_column($this->ledger->payloads(), 'change'));
        self::assertSame(0, new PendingHeads($this->entities, $this->clock)->failuresOf('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z')));
        self::assertContainsEquals(RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'), new PendingHeads($this->entities, $this->clock)->dueFor('alpha'));
        self::assertIsArray(array_find($this->logger->logged, static fn (array $line): bool => 'A head\'s back-off could not be stored; it stays due for the next tick.' === $line[0]));
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

    public function testTheSteeringLabelsOfThePullRequestReachTheRoundItStarts(): void
    {
        $this->steering = new Labels('review:full', 'model:strong');

        $this->startRoundOnGreenHead();

        self::assertSame(['review:full', 'model:strong'], [...$this->commands->started[0]->start->labels]);
    }

    public function testTheStaleCheckJudgesAnEarlierRoundByTheLabelsItStartedWithNotByTheLabelsReadAgain(): void
    {
        $this->steering = new Labels('review:full');
        $this->startRoundOnGreenHead();
        $this->steering = new Labels();
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:05:00Z'));

        $this->tick();

        self::assertSame(['review:full'], [...$this->queries->identitiesAsked[0]->round->start->labels]);
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
            // Expected, because the tick must fail; the lock lapses with its time to live.
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

    public function testEveryHeadLockIsGivenBackBeforeTheFailureOfTheFirstIsReportedOnce(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('6', self::PENDING, '2026-11-02T09:00:00Z'), RecordedForge::change('7', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->locks->deleteFailure = new LockStorageException('the lock store is down');
        $givenBackWhenReported = [];
        $installWide = self::createStub(InstallWideFailures::class);
        $installWide->method('lockStoreFailed')->willReturnCallback(function () use (&$givenBackWhenReported): void {
            $givenBackWhenReported[] = $this->locks->changeLocksGivenBack;
        });
        $this->handler = $this->handler($installWide);

        try {
            $this->tick();
            self::fail('A lock store that fails must fail the tick.');
        } catch (LockReleasingException $failure) {
            self::assertSame('the lock store is down', $failure->getPrevious()?->getMessage());
        }

        self::assertCount(1, $givenBackWhenReported);
        self::assertCount(1, $givenBackWhenReported[0]);
    }

    public function testAFailureOfTheTickIsRethrownWhenGivingBackItsHeadLockFailsToo(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('6', self::UNREADABLE, '2026-11-02T09:00:00Z'));
        $this->useLedger(new InMemoryLedger(0));
        $this->locks->deleteFailure = new LockStorageException('the lock store is down');

        $this->tickExpectingFailure();

        self::assertSame([SharedHold::FailureBackOff], new DoctrineHolds($this->entities, $this->clock)->sharedInEffect());
        $reported = array_first(array_filter($this->logger->logged, static fn (array $line): bool => 'the lock store failed' === ($line[1]['cause'] ?? null)));
        self::assertIsArray($reported);
        $exception = $reported[1]['exception'] ?? null;
        self::assertInstanceOf(LockReleasingException::class, $exception);
        self::assertSame('the lock store is down', $exception->getPrevious()?->getMessage());
    }

    public function testAFailureOfTheTickIsRethrownWhenTheLockStoreStaysDownWhileItsHeadLockIsGivenBack(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('6', self::UNREADABLE, '2026-11-02T09:00:00Z'));
        $this->useLedger(new InMemoryLedger(0));
        $this->locks->changeLocksStayDown = true;

        $this->tickExpectingFailure();

        self::assertSame([SharedHold::FailureBackOff], new DoctrineHolds($this->entities, $this->clock)->sharedInEffect());
    }

    public function testTheFirstFailedGiveBackIsRethrownWhenTheLockStoreStaysDown(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->locks->changeLocksStayDown = true;

        try {
            $this->tick();
            self::fail('A lock store that fails must fail the tick.');
        } catch (LockReleasingException $failure) {
            self::assertSame('the lock store is down', $failure->getPrevious()?->getMessage());
        }
    }

    public function testAFailureOfTheTickIsRethrownWhenReportingTheLockStoreFailureFailsToo(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('6', self::UNREADABLE, '2026-11-02T09:00:00Z'));
        $this->useLedger(new InMemoryLedger(0));
        $this->locks->deleteFailure = new LockStorageException('the lock store is down');
        $this->handler = $this->handler($this->installWideThatCannotRecordAHold());

        $this->tickExpectingFailure();

        self::assertTrue($this->loggedTheUnwrittenHold());
    }

    public function testAFailedGiveBackIsRethrownWhenReportingItFailsToo(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::PENDING, '2026-11-02T09:00:00Z'));
        $this->locks->deleteFailure = new LockStorageException('the lock store is down');
        $this->handler = $this->handler($this->installWideThatCannotRecordAHold());

        try {
            $this->tick();
            self::fail('A lock store that fails must fail the tick.');
        } catch (LockReleasingException $failure) {
            self::assertSame('the lock store is down', $failure->getPrevious()?->getMessage());
        }

        self::assertTrue($this->loggedTheUnwrittenHold());
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
                    'labels' => [],
                ], ['stale' => Types::BOOLEAN, 'queued_recorded' => Types::BOOLEAN, 'labels' => Types::JSON]);
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

    public function testAHeadWhoseInstallWideRecordFailsStillBacksOffAloneAndTheNextHeadStartsItsRound(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('6', self::UNREADABLE, '2026-11-02T09:00:00Z'), RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        $installWide = self::createStub(InstallWideFailures::class);
        $installWide->method('itemFailed')->willThrowException(new RuntimeException('budget_item_failure is locked'));
        $this->handler = $this->handler($installWide);

        $this->tick();

        self::assertSame(1, new PendingHeads($this->entities, $this->clock)->failuresOf('alpha', RecordedForge::change('6', self::UNREADABLE, '2026-11-02T09:00:00Z')));
        self::assertSame([LedgerEventKind::HeadFailed, LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertSame('7', $this->ledger->payloads()[1]['change']);
        $notRecorded = array_find($this->logger->logged, static fn (array $line): bool => 'The install-wide failure record failed; the head still backs off alone.' === $line[0]);
        self::assertIsArray($notRecorded);
        $exception = $notRecorded[1]['exception'] ?? null;
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('budget_item_failure is locked', $exception->getMessage());
    }

    public function testTheSameErrorOnTwoHeadsOfOneTickStartsTheFailureBackOff(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('6', self::UNREADABLE, '2026-11-02T09:00:00Z'), RecordedForge::change('8', self::UNREADABLE, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertSame([SharedHold::FailureBackOff], new DoctrineHolds($this->entities, $this->clock)->sharedInEffect());
        self::assertSame([LedgerEventKind::HeadFailed, LedgerEventKind::HeadFailed], $this->ledger->kinds());
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
        $this->queries->move = new HeadMove(HeadMoveKind::Commits, new Paths('src/Feature.php'));
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:05:00Z'));

        $this->tick();

        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::VerdictsStale, LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertSame('round-1', $this->queries->asked[0]->roundId);
        self::assertTrue($this->storedStale(self::GREEN));
    }

    public function testAStaleRoundIsRecordedOnceWhileTheMovedHeadIsReadAgain(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->move = new HeadMove(HeadMoveKind::Rewritten, new Paths());
        $this->listing->opens('alpha', RecordedForge::change('7', self::PENDING, '2026-11-02T10:05:00Z'));
        $this->tick();

        $this->clock->modify('+61 seconds');
        $this->tick();

        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::HeadPending, LedgerEventKind::VerdictsStale, LedgerEventKind::HeadPending], $this->ledger->kinds());
    }

    public function testAHeadThatMovesWhileItsRoundIsStillStartingMakesThatRoundsVerdictsStaleOnTheNextTick(): void
    {
        $this->queries->move = new HeadMove(HeadMoveKind::Commits, new Paths('src/Feature.php'));
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
        $this->queries->move = new HeadMove(HeadMoveKind::Commits, new Paths('docs/feature.md'));
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:05:00Z'));

        $this->tick();

        self::assertNotContains(LedgerEventKind::VerdictsStale, $this->ledger->kinds());
        self::assertFalse($this->storedStale(self::GREEN));
    }

    public function testAMovedHeadWithPendingCiMakesTheVerdictsStaleButStartsNoNewRound(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->move = new HeadMove(HeadMoveKind::Rewritten, new Paths());
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

    public function testARedHeadIsRecordedWithItsHeadAndNoCiLogText(): void
    {
        $this->startRoundOnGreenHead();
        $this->listing->opens('alpha', RecordedForge::change('7', self::RED, '2026-11-02T10:05:00Z'));

        $this->tick();

        $redKeys = [];
        $redHeads = [];
        foreach ($this->ledger->kinds() as $index => $kind) {
            if (LedgerEventKind::HeadRed === $kind) {
                $redKeys[] = array_keys($this->ledger->payloads()[$index]);
                $redHeads[] = $this->ledger->payloads()[$index]['head'];
            }
        }

        self::assertSame([['project', 'change', 'head', 'read_again_at']], $redKeys);
        self::assertSame([str_repeat(self::RED, 40)], $redHeads);
    }

    public function testARedMovedHeadIsCheckedAgainForStaleVerdictsOnceItsCommitArrives(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->fetched = false;
        $this->queries->move = new HeadMove(HeadMoveKind::Rewritten, new Paths());
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
        $this->queries->move = new HeadMove(HeadMoveKind::Rewritten, new Paths());
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
        $this->queries->move = new HeadMove(HeadMoveKind::Rewritten, new Paths());
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
        $this->queries->move = new HeadMove(HeadMoveKind::Commits, new Paths('docs/feature.md'));
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:05:00Z'));
        $this->tick();
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:10:00Z'));

        $this->tick();

        self::assertCount(2, $this->commands->started);
    }

    public function testAGreenHeadIsBootedWithMainsHarnessBeforeItsRoundStarts(): void
    {
        $bootedBeforeTheStart = [];
        $this->commands->whileStarting = function () use (&$bootedBeforeTheStart): void {
            $bootedBeforeTheStart = $this->queries->bootedHeads;
        };
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertCount(1, $bootedBeforeTheStart);
        self::assertSame(str_repeat(self::GREEN, 40), $bootedBeforeTheStart[0]->head->commit->hash);
        self::assertSame(str_repeat(self::BASE, 40), $bootedBeforeTheStart[0]->main->hash);
    }

    public function testAnUntrustedAuthorsGreenHeadIsNeverBootedAndItsRoundStillStarts(): void
    {
        $this->authorTrusted = false;
        $this->queries->headBoot = BootOutcome::failed('The server did not start.');
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertSame([], $this->queries->bootedHeads);
        self::assertCount(1, $this->commands->started);
        self::assertSame([LedgerEventKind::HeadBootSkipped, LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertSame('boot_skipped_untrusted_author', $this->ledger->payloads()[0]['cause']);
    }

    public function testATrustCheckThatFailsBootsNothingAndTheRoundStillStarts(): void
    {
        $this->trustCheckDown = true;
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertSame([], $this->queries->bootedHeads);
        self::assertCount(1, $this->commands->started);
        self::assertSame([LedgerEventKind::HeadBootSkipped, LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertTrue(array_any($this->logger->logged, static fn (array $line): bool => 'The pull request author counts as untrusted, because the trust check failed.' === $line[0]));
    }

    public function testATrustedAuthorsGreenHeadIsBootedAndRecordsNoSkippedBoot(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertCount(1, $this->queries->bootedHeads);
        self::assertSame([LedgerEventKind::RoundQueued], $this->ledger->kinds());
    }

    public function testAHeadWhoseCiIsNotGreenIsNotBooted(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'), RecordedForge::change('2', self::RED, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertSame([], $this->queries->bootedHeads);
    }

    public function testAGreenHeadThatFailsToBootWhileMainBootsGoesBackLikeRedCiWithItsReason(): void
    {
        $this->queries->headBoot = BootOutcome::failed('The server did not start.');
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertSame([], $this->commands->started);
        self::assertSame([LedgerEventKind::HeadRed], $this->ledger->kinds());
        self::assertSame('boot_failed', $this->ledger->payloads()[0]['cause']);
        self::assertSame('The server did not start.', $this->ledger->payloads()[0]['reason']);
        self::assertSame(0, $this->entities->getRepository(PendingHead::class)->count([]));
    }

    public function testAHeadThatFailsToBootIsNotReadAgainUntilItsPullRequestChanges(): void
    {
        $this->queries->headBoot = BootOutcome::failed('The server did not start.');
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->tick();
        $this->clock->modify('+1 hour');

        $this->tick();

        self::assertCount(1, $this->queries->bootedHeads);
    }

    public function testAFailedBootWithdrawsTheApprovalsOfTheRoundAlreadyStartedOnThatHead(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->headBoot = BootOutcome::failed('The server did not start.');
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:05:00Z'));

        $this->tick();

        self::assertSame(['round-1'], $this->commands->markedStale);
        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::HeadRed, LedgerEventKind::VerdictsStale], $this->ledger->kinds());
        self::assertTrue($this->storedStale(self::GREEN));
    }

    public function testAWithdrawalReviewCannotTakeLeavesTheHeadDueAndIsSentAgainOnItsNextTry(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->headBoot = BootOutcome::failed('The server did not start.');
        $this->commands->markFailure = new RuntimeException('the review database is down');
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:05:00Z'));
        $this->tick();
        $this->commands->markFailure = null;

        $this->clock->modify('+61 seconds');
        $this->tick();

        self::assertSame(['round-1', 'round-1'], $this->commands->markedStale);
        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::HeadRed, LedgerEventKind::VerdictsStale, LedgerEventKind::HeadFailed, LedgerEventKind::HeadRed], $this->ledger->kinds());
        self::assertSame(0, $this->entities->getRepository(PendingHead::class)->count([]));
    }

    public function testAWithdrawalReviewRefusesIsTheHeadFailedCauseWithReviewsReason(): void
    {
        $this->failToWithdrawOnARefusedHead();

        self::assertSame(VerdictsWithdrawalRefused::class.": Review refused to withdraw the verdicts of the head's round: RuntimeException: the review database is down", $this->causeOf(LedgerEventKind::HeadFailed));
    }

    public function testAWithdrawalRefusedThroughTheCommandBusNamesTheHandlersOwnException(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->headBoot = BootOutcome::failed('The server did not start.');
        $this->commands->markFailure = new HandlerFailedException(new Envelope(new MarkVerdictsStale('round-1')), [new LogicException('No round round-1 is known.')]);
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:05:00Z'));

        $this->tick();

        self::assertStringEndsWith(": Review refused to withdraw the verdicts of the head's round: LogicException: No round round-1 is known.", $this->causeOf(LedgerEventKind::HeadFailed));
    }

    public function testAHeadWhoseWithdrawalIsRefusedFourTimesIsHandedOverWithTheRefusalAsItsCause(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->headBoot = BootOutcome::failed('The server did not start.');
        $this->commands->markFailure = new RuntimeException('the review database is down');
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:05:00Z'));

        $this->tickEvery('+20 minutes', 4);

        self::assertStringStartsWith(VerdictsWithdrawalRefused::class.': ', $this->causeOf(LedgerEventKind::HeadHandedOver));
    }

    public function testAWithdrawalReviewCannotTakeIsSentAgainWhenTheHeadBootsOnItsNextTry(): void
    {
        $this->failToWithdrawOnARefusedHead();
        $this->queries->headBoot = BootOutcome::booted();

        $this->clock->modify('+61 seconds');
        $this->tick();

        self::assertSame(['round-1', 'round-1'], $this->commands->markedStale);
        self::assertCount(2, $this->commands->started);
    }

    public function testAWithdrawalReviewCannotTakeIsSentAgainWhenTheHeadsBootCrashesOnItsNextTry(): void
    {
        $this->failToWithdrawOnARefusedHead();
        $this->queries->headBoot = BootOutcome::crashed('composer could not run');

        $this->clock->modify('+61 seconds');
        $this->tick();

        self::assertSame(['round-1', 'round-1'], $this->commands->markedStale);
    }

    public function testAWithdrawalReviewCannotTakeIsSentAgainWhenTheHeadHasMovedOnItsNextTry(): void
    {
        $this->failToWithdrawOnARefusedHead();
        $this->queries->headBoot = BootOutcome::booted();
        $this->queries->move = new HeadMove(HeadMoveKind::Commits, new Paths('src/Feature.php'));
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:10:00Z'));

        $this->clock->modify('+61 seconds');
        $this->tick();

        self::assertSame(['round-1', 'round-1'], $this->commands->markedStale);
    }

    public function testAHeadThatBootsAgainStartsNoFreshRoundWhileItsWithdrawalIsStillRefused(): void
    {
        $this->failToWithdrawOnARefusedHead();
        $this->queries->headBoot = BootOutcome::booted();
        $this->commands->markFailure = new RuntimeException('the review database is still down');

        $this->clock->modify('+61 seconds');
        $this->tick();

        self::assertCount(1, $this->commands->started);
        self::assertTrue($this->storedStale(self::GREEN));
    }

    public function testATakenWithdrawalIsNotSentAgain(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->headBoot = BootOutcome::failed('The server did not start.');
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:05:00Z'));
        $this->tick();
        $this->queries->headBoot = BootOutcome::booted();
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:10:00Z'));

        $this->tick();

        self::assertSame(['round-1'], $this->commands->markedStale);
    }

    public function testAMovedHeadThatFailsToBootLeavesTheEarlierHeadsRoundToTheStaleCheckWithoutWithdrawingIt(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->headBoot = BootOutcome::failed('The server did not start.');
        $this->queries->move = new HeadMove(HeadMoveKind::Commits, new Paths('src/Feature.php'));
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:05:00Z'));

        $this->tick();

        self::assertSame([], $this->commands->markedStale);
        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::HeadRed, LedgerEventKind::VerdictsStale], $this->ledger->kinds());
        self::assertCount(1, $this->commands->started);
    }

    public function testAHeadWhoseApprovalsWereWithdrawnStartsAFreshRoundOnceItBootsAgain(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->headBoot = BootOutcome::failed('The server did not start.');
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:05:00Z'));
        $this->tick();
        $this->queries->headBoot = BootOutcome::booted();
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:10:00Z'));

        $this->tick();

        self::assertCount(2, $this->commands->started);
        self::assertFalse($this->storedStale(self::GREEN));
    }

    public function testAHeadThatReturnsAndFailsToBootWithdrawsTheApprovalsOfItsRoundAlreadyFoundStale(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->move = new HeadMove(HeadMoveKind::Commits, new Paths('src/Feature.php'));
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:05:00Z'));
        $this->tick();
        $this->queries->headBoot = BootOutcome::failed('The server did not start.');
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:10:00Z'));

        $this->tick();

        self::assertContains('round-1', $this->commands->markedStale);
        self::assertSame(1, $this->staleLinesOf('round-1'));
    }

    public function testAHeadThatFailsToBootAgainIsNotWithdrawnASecondTime(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->headBoot = BootOutcome::failed('The server did not start.');
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:05:00Z'));
        $this->tick();
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:10:00Z'));

        $this->tick();

        self::assertSame(['round-1'], $this->commands->markedStale);
        self::assertSame(1, $this->staleLinesOf('round-1'));
    }

    public function testAReturningHeadThatFailsToBootIsWithdrawnAlthoughTheStaleCheckOfTheHeadBetweenFails(): void
    {
        $this->returnToAHeadThatFailsToBootWhileTheStaleCheckOfTheHeadBetweenFails();

        self::assertSame(['round-1'], $this->commands->markedStale);
    }

    public function testAReturningHeadThatFailsToBootStaysDueWhileTheStaleCheckOfTheHeadBetweenFails(): void
    {
        $this->returnToAHeadThatFailsToBootWhileTheStaleCheckOfTheHeadBetweenFails();

        self::assertSame(LedgerEventKind::HeadFailed, array_last($this->ledger->kinds()));
        self::assertSame(1, $this->entities->getRepository(PendingHead::class)->count([]));
    }

    public function testAReturningHeadThatFailedToBootIsWithdrawnWhenItsNextBootCrashes(): void
    {
        $this->returnToAHeadThatFailsToBootWhileTheStaleCheckOfTheHeadBetweenFails();
        $this->queries->fetched = true;
        $this->queries->headBoot = BootOutcome::crashed('composer could not run');

        $this->clock->modify('+61 seconds');
        $this->tick();

        self::assertSame(['round-1'], $this->commands->markedStale);
    }

    public function testAReturningHeadThatFailedToBootIsWithdrawnWhenItsNextBootPasses(): void
    {
        $this->returnToAHeadThatFailsToBootWhileTheStaleCheckOfTheHeadBetweenFails();
        $this->queries->fetched = true;
        $this->queries->headBoot = BootOutcome::booted();

        $this->clock->modify('+61 seconds');
        $this->tick();

        self::assertSame(['round-1'], $this->commands->markedStale);
    }

    public function testAHeadWhoseBootCrashesStillStartsItsRound(): void
    {
        $this->queries->headBoot = BootOutcome::crashed('composer could not run');
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertCount(1, $this->commands->started);
    }

    public function testAHeadThatFailsToBootStillStartsItsRoundWhenMainDoesNotBootEither(): void
    {
        $this->queries->mainBoot = BootOutcome::failed('main is broken');
        $this->queries->headBoot = BootOutcome::failed('The server did not start.');
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertCount(1, $this->commands->started);
        self::assertSame([LedgerEventKind::RoundQueued], $this->ledger->kinds());
    }

    public function testAGateThatCrashesNeverStopsTheReview(): void
    {
        $this->queries->gateDown = true;
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertCount(1, $this->commands->started);
        self::assertSame([LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertTrue(array_any($this->logger->logged, static fn (array $line): bool => 'The boot gate failed, so the review starts without it.' === $line[0]));
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::rowsIn(self::getContainer());
        $this->listing = new RecordedForge();
        $this->checks = new RecordedChecks([str_repeat(self::PENDING, 40) => 'pending', str_repeat(self::GREEN, 40) => 'green', str_repeat(self::RED, 40) => 'red', str_repeat(self::NEXT_GREEN, 40) => 'rerun']);
        $this->commands = new RecordingCommandBus();
        $this->ledger = new InMemoryLedger();
        $this->clock = new MockClock('2026-11-02T10:00:00+00:00');
        $recorded = new VerdictIdentity(new CommitRange(new CommitId(str_repeat(self::BASE, 40)), new CommitId(str_repeat(self::GREEN, 40))), ReviewTerms::of('Review it.', 'bin/check', $this->panel()));
        $this->queries = new StandingQueryBus($recorded, $entities);
        $this->locks = new BeforeChangeLockStore();
        $this->logger = new RecordingLogger();
        $this->steering = new Labels();
        $this->handler = $this->handler();
    }

    private function moveFromGreenToNextGreenAndBack(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->move = new HeadMove(HeadMoveKind::Commits, new Paths('src/Feature.php'));
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:05:00Z'));
        $this->tick();
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:10:00Z'));

        $this->tick();
    }

    // A, then B, then A failing to boot while the repository cannot be read, so the stale check of B's open round throws in the tick that refuses A.
    private function returnToAHeadThatFailsToBootWhileTheStaleCheckOfTheHeadBetweenFails(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->move = new HeadMove(HeadMoveKind::Commits, new Paths('src/Feature.php'));
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:05:00Z'));
        $this->tick();
        $this->queries->headBoot = BootOutcome::failed('The server did not start.');
        $this->queries->fetched = false;
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:10:00Z'));

        $this->tick();
    }

    private function failToWithdrawOnARefusedHead(): void
    {
        $this->startRoundOnGreenHead();
        $this->queries->headBoot = BootOutcome::failed('The server did not start.');
        $this->commands->markFailure = new RuntimeException('the review database is down');
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T10:05:00Z'));
        $this->tick();
        $this->commands->markFailure = null;
    }

    private function staleLinesOf(string $round): int
    {
        $lines = 0;

        foreach ($this->ledger->kinds() as $index => $kind) {
            if (LedgerEventKind::VerdictsStale === $kind && $round === $this->ledger->payloads()[$index]['round']) {
                ++$lines;
            }
        }

        return $lines;
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

    private function causeOf(LedgerEventKind $kind): string
    {
        $line = array_search($kind, $this->ledger->kinds(), true);
        self::assertIsInt($line);

        $cause = $this->ledger->payloads()[$line]['cause'];
        self::assertIsString($cause);

        return $cause;
    }

    private function tickEvery(string $interval, int $ticks): void
    {
        foreach (range(1, $ticks) as $ignored) {
            $this->tick();
            $this->clock->modify($interval);
        }
    }

    private function installWideThatCannotRecordAHold(): InstallWideFailures
    {
        $installWide = self::createStub(InstallWideFailures::class);
        $installWide->method('lockStoreFailed')->willThrowException(new RuntimeException('the hold cannot be written'));

        return $installWide;
    }

    private function loggedTheUnwrittenHold(): bool
    {
        return array_any($this->logger->logged, static function (array $line): bool {
            $exception = $line[1]['exception'] ?? null;

            return $exception instanceof RuntimeException && 'the hold cannot be written' === $exception->getMessage();
        });
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

    // The waits may go to their own pending heads, so a test can break the wait for a forge pause and nothing else.
    private function handler(?InstallWideFailures $installWide = null, ?PendingHeads $waits = null, ?PendingHeads $backOffs = null): WatchPullRequestsHandler
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
        $installWide ??= new DoctrineInstallWideFailures($this->entities, $doctrine, new DoctrineHolds($this->entities, $this->clock), $this->clock, $logger);
        $backOff = new HeadBackOff(new FailedHeads($backOffs ?? $pending, $ledger, $this->clock, $redactor, $this->entities->getConnection(), $installWide, $logger, $doctrine), $waits ?? $pending, $doctrine, $logger);
        $decisions = new HeadDecisions(new AdmitHeads($this->checks, new AdmissionDecision(), $this->clock, $logger), new AdmissionDecision(), $backOff);
        $steering = self::createStub(ChangeLabels::class);
        $steering->method('steeringOf')->willReturnCallback(fn (): Labels => $this->steering);
        $starts = new RoundStarts($this->commands, $rounds, $ledger, $steering);
        // Main's boot lines go to a ledger of their own, because each test reads only the lines of the heads it settles.
        $authors = self::createStub(AuthorTrust::class);
        $authors->method('isAuthorTrusted')->willReturnCallback(fn (): bool => $this->trustCheckDown ? throw new RuntimeException('The permission API is down.') : $this->authorTrusted);
        $gate = new BootGate($this->queries, $authors, new BootResults($this->queries, $this->entities, $this->clock, new WatcherLedger(new InMemoryLedger()), $redactor, $logger, $doctrine, new LockFactory(new InMemoryStore())), $ledger, $logger);
        $settle = new SettleHeads(new SettleHead($gate, new StaleVerdicts($this->queries, $this->commands, $rounds, $ledger), new HeadsWithoutRound($ledger, $pending), $starts), $starts, $backOff);
        $projects = new WatchedProjects(['alpha' => ['repository' => 'repo', 'base' => 'main', 'task' => 'Review it.']]);
        // Without opening records the recognition writes to a ledger of its own, because those tests read only the lines of the heads.
        $recognition = $this->openings instanceof Ledger ? new ChangesHandedToIntakt($this->openings, $ledger, $this->authorship ?? self::createStub(ChangeAuthorship::class), $logger, $this->commands) : new ChangesHandedToIntakt(new InMemoryLedger(), new WatcherLedger(new InMemoryLedger()), $this->authorship ?? self::createStub(ChangeAuthorship::class), $logger, $this->commands);

        return new WatchPullRequestsHandler($projects, new ChangedHeads($find, $pending, $ledger, $this->entities->getConnection(), $recognition), new LookAtHeads(new HeadLocks($pending, $locks, $installWide, $logger), $decisions, $settle));
    }

    private function panel(): Panel
    {
        return new Panel([new PanelReviewer(new ReviewerRole('core', 'Review the change.'), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10)], QuorumPolicy::All);
    }
}
