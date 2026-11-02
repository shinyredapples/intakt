<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\StartReview;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Watcher\Domain\AdmissionDecision;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\Domain\PendingHead;
use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Core\Watcher\UseCase\AdmitHeads\AdmitHeads;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\FindChangedPullRequests;
use Intakt\Core\Watcher\UseCase\FindChangedPullRequests\SeenListings;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\PendingHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\RoundStarts;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\StaleVerdicts;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\StartedRounds;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchedProject;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchedProjects;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherLedger;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchPullRequests;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchPullRequestsHandler;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangedFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\Diff;
use Intakt\Plugin\Forge\Contract\FileStatus;
use Intakt\Plugin\Forge\Contract\Forge;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Watcher\UseCase\AdmitHeads\RecordedChecks;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordedForge;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordingLogger;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[Group('persistence')]
#[CoversClass(WatchPullRequestsHandler::class)]
#[CoversClass(PendingHeads::class)]
#[CoversClass(PendingHead::class)]
#[CoversClass(StartedRounds::class)]
#[CoversClass(StartedRound::class)]
#[CoversClass(RoundStarts::class)]
#[CoversClass(StaleVerdicts::class)]
#[CoversClass(WatcherLedger::class)]
#[CoversClass(WatchedProjects::class)]
#[UsesClass(WatchedProject::class)]
#[UsesClass(WatchPullRequests::class)]
#[UsesClass(FindChangedPullRequests::class)]
#[UsesClass(SeenListings::class)]
#[UsesClass(AdmitHeads::class)]
#[UsesClass(AdmissionDecision::class)]
#[UsesClass(HeadDecision::class)]
final class WatchPullRequestsHandlerTest extends KernelTestCase
{
    private const string PENDING = 'a';
    private const string GREEN = 'b';
    private const string NEXT_GREEN = 'e';
    private const string BASE = 'f';

    private EntityManagerInterface $entities;
    private RecordedForge $listing;
    private RecordedChecks $checks;
    private RecordingCommandBus $commands;
    private InMemoryLedger $ledger;
    private MockClock $clock;
    private WatchPullRequestsHandler $handler;
    private StandingQueryBus $queries;
    private ?HeadMove $move = null;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->listing = new RecordedForge();
        $this->checks = new RecordedChecks([str_repeat(self::PENDING, 40) => 'pending', str_repeat(self::GREEN, 40) => 'green', str_repeat(self::NEXT_GREEN, 40) => 'rerun']);
        $this->commands = new RecordingCommandBus();
        $this->ledger = new InMemoryLedger();
        $this->clock = new MockClock('2026-11-02T10:00:00+00:00');
        $this->queries = new StandingQueryBus(new VerdictIdentity(new CommitRange(new CommitId(str_repeat(self::BASE, 40)), new CommitId(str_repeat(self::GREEN, 40))), ReviewTerms::of('Review it.', 'bin/check', $this->panel())));
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
        self::assertSame([], $this->entities->getRepository(PendingHead::class)->findAll());
    }

    public function testAGreenHeadStartsExactlyOneRoundForItsChange(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertCount(1, $this->commands->started);
        self::assertEquals(new StartReview('alpha', 'Review it.', new RequestedRound('7', null, new ChangeSource('repo', 'main'))), $this->commands->started[0]);
        self::assertSame([LedgerEventKind::RoundQueued], $this->ledger->kinds());
    }

    public function testAGreenHeadSeenAgainOnASecondTickStartsNoSecondRound(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->tick();
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:30:00Z'));

        $this->tick();

        self::assertCount(1, $this->commands->started);
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

        self::assertCount(1, $this->commands->started);
        self::assertSame([LedgerEventKind::RoundQueued], $this->ledger->kinds());
    }

    public function testAFailedStartReleasesTheHeadSoTheNextTickStartsItsRound(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->commands->whileStarting = static fn () => throw new RuntimeException('the bus is down');
        try {
            $this->tick();
            self::fail('The failed start was swallowed.');
        } catch (RuntimeException) {
            $this->commands->whileStarting = null;
        }
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:30:00Z'));

        $this->tick();

        self::assertCount(2, $this->commands->started);
    }

    public function testAHeadThatMovesDuringARoundMakesThatRoundsVerdictsStale(): void
    {
        $this->startRoundOnGreenHead();
        $this->move = new HeadMove(HeadMoveKind::Commits, ['src/Feature.php']);
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:05:00Z'));

        $this->tick();

        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::VerdictsStale, LedgerEventKind::RoundQueued], $this->ledger->kinds());
        self::assertSame('round-1', $this->queries->asked[0]->roundId);
        self::assertTrue($this->startedRound(self::GREEN)->isStale());
    }

    public function testAHeadThatMovesOnlyInDocumentationLeavesTheVerdictsStanding(): void
    {
        $this->startRoundOnGreenHead();
        $this->move = new HeadMove(HeadMoveKind::Commits, ['docs/feature.md']);
        $this->listing->opens('alpha', RecordedForge::change('7', self::NEXT_GREEN, '2026-11-02T10:05:00Z'));

        $this->tick();

        self::assertNotContains(LedgerEventKind::VerdictsStale, $this->ledger->kinds());
        self::assertFalse($this->startedRound(self::GREEN)->isStale());
    }

    public function testAMovedHeadWithPendingCiMakesTheVerdictsStaleButStartsNoNewRound(): void
    {
        $this->startRoundOnGreenHead();
        $this->move = new HeadMove(HeadMoveKind::Rewritten, []);
        $this->listing->opens('alpha', RecordedForge::change('7', self::PENDING, '2026-11-02T10:05:00Z'));

        $this->tick();

        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::VerdictsStale, LedgerEventKind::HeadPending], $this->ledger->kinds());
        self::assertCount(1, $this->commands->started);
    }

    public function testATickOverOnePendingAndOneGreenPullRequestRecordsEachInTheLedger(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('1', self::PENDING, '2026-11-02T09:00:00Z'), RecordedForge::change('2', self::GREEN, '2026-11-02T09:00:00Z'));

        $this->tick();

        self::assertSame([LedgerEventKind::RoundQueued, LedgerEventKind::HeadPending], $this->ledger->kinds());
        self::assertSame('2', $this->ledger->payloads()[0]['change']);
        self::assertSame('1', $this->ledger->payloads()[1]['change']);
    }

    private function startRoundOnGreenHead(): void
    {
        $this->listing->opens('alpha', RecordedForge::change('7', self::GREEN, '2026-11-02T09:00:00Z'));
        $this->tick();
    }

    private function tick(): void
    {
        ($this->handler)(new WatchPullRequests('alpha'));
    }

    private function startedRound(string $head): StartedRound
    {
        $round = $this->entities->find(StartedRound::class, ['project' => 'alpha', 'change' => '7', 'head' => str_repeat($head, 40)]);
        self::assertInstanceOf(StartedRound::class, $round);
        $this->entities->refresh($round);

        return $round;
    }

    private function handler(): WatchPullRequestsHandler
    {
        $ledger = new WatcherLedger($this->ledger);
        $rounds = new StartedRounds($this->entities, new LockFactory(new InMemoryStore()));
        $logger = new RecordingLogger();
        $find = new FindChangedPullRequests($this->listing, new SeenListings($this->entities, new LockFactory(new InMemoryStore())), $logger);
        $admit = new AdmitHeads($this->checks, new AdmissionDecision(), $this->clock, $logger);
        $stale = new StaleVerdicts($this->forge(), $this->panels(), $this->queries, $rounds, $ledger);

        return new WatchPullRequestsHandler(new WatchedProjects(['alpha' => ['repository' => 'repo', 'base' => 'main', 'task' => 'Review it.']]), $find, new PendingHeads($this->entities, $this->clock), $stale, $admit, new RoundStarts($this->commands, $rounds, $ledger), $ledger);
    }

    private function forge(): Forge
    {
        $forge = self::createStub(Forge::class);
        $forge->method('change')->willReturnCallback(fn (ChangeId $id): Change => new Change($id, new CommitRange(new CommitId(str_repeat(self::BASE, 40)), new CommitId(str_repeat(self::NEXT_GREEN, 40))), new Diff('', new ChangedFiles(new ChangedFile('src/Feature.php', FileStatus::Modified)))));
        $forge->method('headMoveSince')->willReturnCallback(fn (): HeadMove => $this->move ?? new HeadMove(HeadMoveKind::Unmoved, []));

        return $forge;
    }

    private function panels(): Panels
    {
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn($this->panel());

        return $panels;
    }

    private function panel(): Panel
    {
        return new Panel([new PanelReviewer(new ReviewerRole('core', 'Review the change.'), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10)], QuorumPolicy::All);
    }
}
