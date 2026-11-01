<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\RoundVerdicts;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Domain\ChangeFindings;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\ReviewTarget;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Domain\TrackedFinding;
use Intakt\Core\Review\UseCase\ReadVerdicts\VerdictsOfRound;
use Intakt\Core\Review\UseCase\ReadVerdicts\VerdictsOfRoundHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\BlockReviewerOfFailedMessage;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerLeases;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerMoves;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRoundHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

// A round on the application's workflows and a SQLite database, with a bus that keeps the messages instead of queueing them, so a test delivers them in the order it chooses.
final readonly class RoundFixture
{
    public const string TASK = 'Add the thing.';
    public const string CHANGE = 'feat/thing';

    public EntityManagerInterface $entities;
    public RecordingBus $bus;
    public MockClock $clock;
    public BufferingLogger $log;
    private Ledger $ledger;
    private SerializerInterface $serializer;

    public function __construct(private ContainerInterface $container)
    {
        $entities = $container->get('doctrine.orm.entity_manager');
        assert($entities instanceof EntityManagerInterface);
        $this->entities = $entities;
        $serializer = $container->get(SerializerInterface::class);
        assert($serializer instanceof SerializerInterface);
        $this->serializer = $serializer;
        $this->bus = new RecordingBus();
        $this->clock = new MockClock('2026-10-30 12:00:00', 'UTC');
        $this->log = new BufferingLogger();
        $registry = $container->get('doctrine');
        assert($registry instanceof ManagerRegistry);
        $this->ledger = new DoctrineLedger($registry, $this->clock);

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
        return $this->startWith(new RunReviewRound(new ReviewTarget($this->subject(), $this->identityAt(SampleIdentity::HEAD, $reviewers)), $this->panelOf($reviewers), $change), $queue);
    }

    // The identity names its own task and panel size, so a test can hand in one that does not describe the round.
    public function startWithIdentityOf(string $task, int $panelSize, int $reviewers): string
    {
        return $this->startWith(new RunReviewRound(new ReviewTarget($this->subject(), SampleIdentity::of($task, $this->panelOf($panelSize))), $this->panelOf($reviewers), self::CHANGE), $this->bus);
    }

    public function identityAt(string $head, int $reviewers): VerdictIdentity
    {
        return SampleIdentity::of(self::TASK, $this->panelOf($reviewers), $head);
    }

    public function verdictsOf(VerdictsOfRound $query): RoundVerdicts
    {
        return new VerdictsOfRoundHandler($this->entities, $this->serializer)($query);
    }

    /**
     * @return list<TrackedFinding>
     */
    public function storedFindings(string $change): array
    {
        $this->entities->clear();
        $stored = $this->entities->find(ChangeFindings::class, ['project' => 'alpha', 'change' => $change]);
        assert($stored instanceof ChangeFindings);
        $tracked = $this->serializer->deserialize($stored->findingsJson(), TrackedFinding::class.'[]', 'json');
        assert(is_array($tracked));

        return array_values(array_filter($tracked, static fn (mixed $finding): bool => $finding instanceof TrackedFinding));
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
        return new ReviewWithReviewerHandler($this->leases($entities, $ledger), $vendor, $this->rounds($entities));
    }

    // A test that stops here has a worker killed right after it took the reviewer, because that is the row a kill leaves behind.
    public function takeAndStop(ReviewWithReviewer $message): RoundReviewer
    {
        return $this->leases($this->entities)->take($message->reviewerId);
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
        $command = new RunReviewRound(new ReviewTarget($this->subject(), $this->identityAt(SampleIdentity::HEAD, $reviewers)), $this->panelOf($reviewers), self::CHANGE);

        return new RunReviewRoundHandler($this->rounds($entities), new RoundLedger(new InMemoryLedger()), $this->bus)($command);
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
        $moves = new ReviewerMoves($this->machine('state_machine.reviewer'), new RoundLedger($ledger ?? $this->ledger));

        return new ReviewerLeases($entities, $moves, $this->clock);
    }

    private function startWith(RunReviewRound $command, MessageBusInterface $queue): string
    {
        return new RunReviewRoundHandler($this->rounds($this->entities), new RoundLedger($this->ledger), $queue)($command);
    }

    private function subject(): ReviewSubject
    {
        return new ReviewSubject('alpha', __DIR__, self::TASK);
    }

    private function rounds(EntityManagerInterface $entities): ReviewRounds
    {
        return new ReviewRounds($entities, $this->clock, new StoredFindings($entities, $this->serializer, $this->machine('state_machine.review_round')));
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
