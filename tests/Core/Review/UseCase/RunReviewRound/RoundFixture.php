<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

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
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundCompletion;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRoundHandler;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Tests\Application\EmptyDatabase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

// A round on the application's workflows and a SQLite database, with a bus that keeps the messages instead of queueing them, so a test delivers them in the order it chooses.
final readonly class RoundFixture
{
    public EntityManagerInterface $entities;
    public RecordingBus $bus;
    private Ledger $ledger;

    public function __construct(private ContainerInterface $container)
    {
        $entities = $container->get('doctrine.orm.entity_manager');
        assert($entities instanceof EntityManagerInterface);
        $this->entities = $entities;
        $this->bus = new RecordingBus();
        $registry = $container->get('doctrine');
        assert($registry instanceof ManagerRegistry);
        $this->ledger = new DoctrineLedger($registry, new MockClock('2026-10-30 12:00:00', 'UTC'));

        EmptyDatabase::in($container);
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
    }

    public function start(int $reviewers): ReviewRound
    {
        $clock = new MockClock('2026-10-30 12:00:00', 'UTC');
        $handler = new RunReviewRoundHandler($this->entities, new RoundLedger($this->ledger), $this->bus, $clock);

        $id = $handler(new RunReviewRound('alpha', __DIR__, 'Add the thing.', $this->panelOf($reviewers)));

        return $this->round($id);
    }

    // A new handler on every delivery, as a restarted worker has, so nothing is carried in memory between two messages.
    public function deliver(ReviewWithReviewer $message, Vendor $vendor): void
    {
        $handler = new ReviewWithReviewerHandler(
            $this->entities,
            $this->machine('state_machine.reviewer'),
            $vendor,
            new RoundLedger($this->ledger),
            new RoundCompletion($this->entities, $this->machine('state_machine.review_round')),
        );

        $handler($message);
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

    /**
     * @return list<LedgerEventKind>
     */
    public function ledgerKinds(): array
    {
        return array_values(array_map(static fn (RecordedEvent $event): LedgerEventKind => $event->event->kind, iterator_to_array($this->ledger->readInOrder())));
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
