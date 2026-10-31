<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Doctrine\DBAL\DriverManager;
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
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\RoundVerdicts;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\RoundReviewer;
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

    public EntityManagerInterface $entities;
    public RecordingBus $bus;
    public MockClock $clock;
    public BufferingLogger $log;
    private Ledger $ledger;

    public function __construct(private ContainerInterface $container)
    {
        $entities = $container->get('doctrine.orm.entity_manager');
        assert($entities instanceof EntityManagerInterface);
        $this->entities = $entities;
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
        return $this->startWith($queue, $reviewers, $this->identityAt(SampleIdentity::HEAD, $reviewers));
    }

    // The identity names its own task and panel size, so a test can hand in one that does not describe the round.
    public function startWithIdentityOf(string $task, int $panelSize, int $reviewers): string
    {
        return $this->startWith($this->bus, $reviewers, SampleIdentity::of($task, $this->panelOf($panelSize)));
    }

    public function identityAt(string $head, int $reviewers): VerdictIdentity
    {
        return SampleIdentity::of(self::TASK, $this->panelOf($reviewers), $head);
    }

    public function verdictsOf(VerdictsOfRound $query): RoundVerdicts
    {
        $serializer = $this->container->get('serializer');
        assert($serializer instanceof SerializerInterface);

        return new VerdictsOfRoundHandler($this->entities, $serializer)($query);
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

    private function leases(EntityManagerInterface $entities, ?Ledger $ledger = null): ReviewerLeases
    {
        $moves = new ReviewerMoves($this->machine('state_machine.reviewer'), new RoundLedger($ledger ?? $this->ledger));

        return new ReviewerLeases($entities, $moves, $this->clock);
    }

    private function startWith(MessageBusInterface $queue, int $reviewers, VerdictIdentity $identity): string
    {
        $handler = new RunReviewRoundHandler($this->rounds($this->entities), new RoundLedger($this->ledger), $queue);

        return $handler(new RunReviewRound(new ReviewSubject('alpha', __DIR__, self::TASK), $this->panelOf($reviewers), $identity));
    }

    private function rounds(EntityManagerInterface $entities): ReviewRounds
    {
        return new ReviewRounds($entities, $this->machine('state_machine.review_round'), $this->clock);
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
