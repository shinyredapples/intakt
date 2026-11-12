<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use Intakt\Core\Memory\Contract\MemoryIndex;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\RoundJudged;
use Intakt\Core\Review\Contract\RoundVerdicts;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Contract\VerdictsOfRound;
use Intakt\Core\Review\Domain\ChangeFindings;
use Intakt\Core\Review\Domain\GoalpostRule;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\ReviewTarget;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Domain\TrackedFinding;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\UseCase\IndexResolvedFindings\IndexResolvedFindings;
use Intakt\Core\Review\UseCase\IndexResolvedFindings\IndexResolvedFindingsHandler;
use Intakt\Core\Review\UseCase\JudgeRound\JudgedRounds;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRoundHandler;
use Intakt\Core\Review\UseCase\JudgeRound\PublishJudgedRound;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReadVerdicts\VerdictsOfRoundHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\BlockReviewerOfFailedMessage;
use Intakt\Core\Review\UseCase\RunReviewRound\LiveRoundProgress;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerLeases;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerMoves;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundOpening;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRoundHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Core\Shadow\Contract\ProjectModes;
use Intakt\Core\Shadow\Infrastructure\ConfiguredProjectModes;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

// A round on the application's workflows and a SQLite database, with a bus that keeps the messages instead of queueing them, so a test delivers them in the order it chooses.
final readonly class RoundFixture
{
    public const string TASK = 'Add the thing.';
    public const string DIFF = "diff --git a/src/Thing.php b/src/Thing.php\n-old\n+new\n";
    public const string CHANGE = 'feat/thing';

    public EntityManagerInterface $entities;
    public RecordingBus $bus;
    public MockClock $clock;
    public BufferingLogger $log;
    public EventDispatcher $events;
    public RecordedRoundUpdates $updates;
    private LockFactory $locks;
    private Ledger $ledger;
    private SerializerInterface $serializer;

    public function __construct(
        private TestContainer $container,
        private ProjectModes $modes = new ConfiguredProjectModes([]),
    ) {
        $entities = $container->get('doctrine.orm.entity_manager');
        $this->entities = $entities;
        $this->serializer = $container->get('serializer.stored');
        $this->bus = new RecordingBus();
        $this->clock = new MockClock('2026-10-30 12:00:00', 'UTC');
        $this->log = new BufferingLogger();
        $this->events = new EventDispatcher();
        $this->updates = new RecordedRoundUpdates();
        $this->locks = new LockFactory(new InMemoryStore());
        $registry = $container->get('doctrine');
        $this->ledger = new DoctrineLedger($registry, $this->clock);
        $this->events->addListener(RoundJudged::class, new PublishJudgedRound($this->liveProgress($this->entities)));

        EmptyDatabase::in($container);
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
    }

    public function start(int $reviewers): ReviewRound
    {
        return $this->round($this->startOn($this->bus, $reviewers));
    }

    public function startOn(MessageBusInterface $queue, int $reviewers): string
    {
        return $this->startFor(self::CHANGE, $queue, $reviewers);
    }

    public function startFor(string $change, MessageBusInterface $queue, int $reviewers): string
    {
        return $this->startWith($this->commandFor($change, $reviewers), $queue);
    }

    public function commandFor(string $change, int $reviewers): RunReviewRound
    {
        return new RunReviewRound(new ReviewTarget($this->subject(), $this->identityAt(SampleIdentity::HEAD, $reviewers)), $this->panelOf($reviewers), new RequestedRound($change));
    }

    public function startHandlerOn(MessageBusInterface $queue): RunReviewRoundHandler
    {
        return new RunReviewRoundHandler(new RoundOpening($this->rounds($this->entities), new RoundLedger($this->ledger, $this->modes, $this->serializer), $queue));
    }

    public function startFromSource(ChangeSource $source): string
    {
        return $this->startWith(new RunReviewRound(new ReviewTarget($this->subject(), $this->identityAt(SampleIdentity::HEAD, 1)), $this->panelOf(1), new RequestedRound(self::CHANGE, null, $source)), $this->bus);
    }

    // The identity names its own task and panel size, so a test can hand in one that does not describe the round.
    public function startWithIdentityOf(string $task, int $panelSize, int $reviewers): string
    {
        return $this->startWith(new RunReviewRound(new ReviewTarget($this->subject(), SampleIdentity::of($task, $this->panelOf($panelSize))), $this->panelOf($reviewers), new RequestedRound(self::CHANGE)), $this->bus);
    }

    public function identityAt(string $head, int $reviewers): VerdictIdentity
    {
        return SampleIdentity::of(self::TASK, $this->panelOf($reviewers), $head);
    }

    public function verdictsOf(VerdictsOfRound $query): RoundVerdicts
    {
        return new VerdictsOfRoundHandler($this->entities, new StoredVerdicts($this->serializer))($query);
    }

    /**
     * @return list<TrackedFinding>
     */
    public function storedFindings(string $change): array
    {
        $this->entities->clear();
        $stored = $this->entities->find(ChangeFindings::class, ['project' => 'alpha', 'change' => $change]);
        assert($stored instanceof ChangeFindings);

        return array_values($this->serializer->deserialize($stored->findingsJson(), TrackedFinding::class.'[]', 'json'));
    }

    /**
     * @return list<Finding>
     */
    public function openFindingsOf(ReviewRound $round): array
    {
        return $this->rounds($this->entities)->openFindingsOf($round);
    }

    /**
     * @return list<ReviewRound>
     */
    public function storedRounds(): array
    {
        $this->entities->clear();

        return $this->entities->getRepository(ReviewRound::class)->findAll();
    }

    // A new handler on every delivery, as a restarted worker has, so nothing is carried in memory between two messages.
    public function deliver(ReviewWithReviewer $message, Vendor $vendor): void
    {
        $this->worker($this->entities, $vendor, $this->ledger)($message);
    }

    public function worker(EntityManagerInterface $entities, Vendor $vendor, Ledger $ledger): ReviewWithReviewerHandler
    {
        return new ReviewWithReviewerHandler($this->leases($entities, $ledger), $vendor, $this->rounds($entities), new GoalpostRule(), $this->liveProgress($entities));
    }

    // The fixture's one lock store, because the workers of one install share theirs and a worker on its own store would never find the publication taken.
    public function liveProgress(EntityManagerInterface $entities): LiveRoundProgress
    {
        return new LiveRoundProgress($this->rounds($entities), $this->updates, $this->locks, new StopRequests($this->ledger, $this->ledgerPayloads()));
    }

    // A test that stops here has a worker killed right after it took the reviewer, because that is the row a kill leaves behind.
    public function takeAndStop(ReviewWithReviewer $message): RoundReviewer
    {
        return $this->leases($this->entities)->take($message->reviewerId);
    }

    public function judge(Vendor $vendor): JudgeRoundHandler
    {
        return new JudgeRoundHandler(new JudgedRounds($this->rounds($this->entities), $this->storedFindingsOn($this->entities), new RoundLedger($this->ledger, $this->modes, $this->serializer)), $vendor, $this->events);
    }

    // The application's queue, because the round's workflow, taken from the container, queues the judging through the application's bus.
    /**
     * @return list<JudgeRound>
     */
    public function judgings(): array
    {
        $queue = $this->container->get('messenger.transport.async');
        assert($queue instanceof InMemoryTransport);

        return array_values(array_filter(array_map(static fn (Envelope $sent): object => $sent->getMessage(), $queue->getSent()), static fn (object $message): bool => $message instanceof JudgeRound));
    }

    public function indexing(MemoryIndex $memory): IndexResolvedFindingsHandler
    {
        return new IndexResolvedFindingsHandler($this->rounds($this->entities), $this->storedFindingsOn($this->entities), $memory);
    }

    /**
     * @return list<IndexResolvedFindings>
     */
    public function indexings(): array
    {
        $queue = $this->container->get('messenger.transport.async');
        assert($queue instanceof InMemoryTransport);

        return array_values(array_filter(array_map(static fn (Envelope $sent): object => $sent->getMessage(), $queue->getSent()), static fn (object $message): bool => $message instanceof IndexResolvedFindings));
    }

    public function failedMessages(?Ledger $ledger = null): BlockReviewerOfFailedMessage
    {
        return new BlockReviewerOfFailedMessage($this->leases($this->entities, $ledger), $this->rounds($this->entities), $this->log);
    }

    // Another worker: its own connection and entity manager, as another messenger:consume process has; its ledger is in memory because the Doctrine ledger writes through the first connection.
    public function secondWorker(Vendor $vendor): SecondWorker
    {
        $entities = new EntityManager(DriverManager::getConnection($this->entities->getConnection()->getParams()), $this->entities->getConfiguration());
        $ledger = new InMemoryLedger();

        return new SecondWorker($this->worker($entities, $vendor, $ledger), $entities, $ledger);
    }

    // A round on its own connection that looked for the change's ledger before another round of the change committed it, the order in which two first rounds of a change meet; its ledger is in memory because the Doctrine ledger writes through the first connection.
    public function startWhileAnotherRoundCommitsAfterItsFirstLook(int $reviewers): string
    {
        $this->useWalOnSqlite();
        $another = fn (): ReviewRound => $this->start(1);
        $connection = DriverManager::getConnection($this->entities->getConnection()->getParams(), new Configuration()->setMiddlewares([new AfterFirstLookAt('change_findings', $another)]));
        $entities = new EntityManager($connection, $this->entities->getConfiguration());
        $command = new RunReviewRound(new ReviewTarget($this->subject(), $this->identityAt(SampleIdentity::HEAD, $reviewers)), $this->panelOf($reviewers), new RequestedRound(self::CHANGE));

        $ledger = new RoundLedger(new InMemoryLedger(), $this->modes, $this->serializer);

        return new RunReviewRoundHandler(new RoundOpening($this->rounds($entities), $ledger, $this->bus))($command);
    }

    public function findingStore(): StoredFindings
    {
        return $this->storedFindingsOn($this->entities);
    }

    public function transactions(): ReviewRounds
    {
        return $this->rounds($this->entities);
    }

    public function round(string $id): ReviewRound
    {
        $this->entities->clear();
        $round = $this->entities->find(ReviewRound::class, Uuid::fromString($id));
        assert($round instanceof ReviewRound);

        return $round;
    }

    public function reviewer(string $id): RoundReviewer
    {
        $this->entities->clear();
        $reviewer = $this->entities->find(RoundReviewer::class, Uuid::fromString($id));
        assert($reviewer instanceof RoundReviewer);

        return $reviewer;
    }

    public function machine(string $id): WorkflowInterface
    {
        $machine = $this->container->get($id);
        assert($machine instanceof WorkflowInterface);

        return $machine;
    }

    public function ledger(): Ledger
    {
        return $this->ledger;
    }

    public function ledgerPayloads(): LedgerPayloads
    {
        return new LedgerPayloads($this->serializer);
    }

    /**
     * @return list<LedgerEventKind>
     */
    public function ledgerKinds(): array
    {
        return array_values(array_map(static fn (RecordedEvent $event): LedgerEventKind => $event->event->kind, iterator_to_array($this->ledger->readInOrder())));
    }

    // WAL, because there a reader does not block the other round's commit but keeps the snapshot from before it, as concurrent workers on SQLite meet it; other engines refuse the pragma and read committed rows already.
    private function useWalOnSqlite(): void
    {
        $connection = $this->entities->getConnection();

        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $connection->executeStatement('PRAGMA journal_mode=WAL');
        }
    }

    private function leases(EntityManagerInterface $entities, ?Ledger $ledger = null): ReviewerLeases
    {
        $moves = new ReviewerMoves($this->machine('state_machine.reviewer'), new RoundLedger($ledger ?? $this->ledger, $this->modes, $this->serializer), $this->serializer, new StopRequests($ledger ?? $this->ledger, $this->ledgerPayloads()));

        return new ReviewerLeases($entities, $moves, $this->clock);
    }

    private function startWith(RunReviewRound $command, MessageBusInterface $queue): string
    {
        return $this->startHandlerOn($queue)($command);
    }

    private function subject(): ReviewSubject
    {
        return new ReviewSubject('alpha', __DIR__, self::TASK, self::DIFF);
    }

    private function rounds(EntityManagerInterface $entities): ReviewRounds
    {
        return new ReviewRounds($entities, $this->clock, $this->storedFindingsOn($entities));
    }

    private function storedFindingsOn(EntityManagerInterface $entities): StoredFindings
    {
        return new StoredFindings($entities, $this->serializer, $this->machine('state_machine.review_round'));
    }

    private function panelOf(int $reviewers): Panel
    {
        $members = array_map(
            static fn (int $number): PanelReviewer => new PanelReviewer(new ReviewerRole('role-'.$number, 'Look at part '.$number.'.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1),
            range(1, $reviewers),
        );

        return new Panel($members, QuorumPolicy::All);
    }
}
