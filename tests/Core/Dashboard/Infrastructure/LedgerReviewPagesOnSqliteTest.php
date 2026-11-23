<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;

// An explicit SQLite connection, because the query plan is read with SQLite's EXPLAIN QUERY PLAN whichever database the persistence suite runs on.
#[Group('persistence')]
#[CoversClass(LedgerReviewPages::class)]
#[UsesClass(DoctrineLedger::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
final class LedgerReviewPagesOnSqliteTest extends KernelTestCase
{
    private Connection $connection;

    private LedgerSelects $selects;

    private DoctrineLedger $ledger;

    public function testTheLiveLookupReadsOnlyTheStartsOfTheRequestedRound(): void
    {
        $this->startReviewer('r1', 'first');
        $this->startReviewer('r10', 'other');
        $this->startReviewer('r1', 'retry');
        $this->selects->clear();

        self::assertSame('retry', $this->pages()->startedReviewerOf('r1', 'reviewer')?->model);

        self::assertNotSame([], $this->selects->queries());
        foreach ($this->selects->queries() as [$sql, $params]) {
            self::assertCount(2, $this->connection->fetchAllAssociative($sql, $params), 'The live read returned starts of another round.');
        }
    }

    public function testTheLiveLookupSearchesTheSubjectIndexInsteadOfScanningTheLedger(): void
    {
        $this->startReviewer('r1', 'first');
        $this->startReviewer('r10', 'other');
        $this->selects->clear();

        self::assertSame('first', $this->pages()->startedReviewerOf('r1', 'reviewer')?->model);

        self::assertNotSame([], $this->selects->queries());
        foreach ($this->selects->queries() as [$sql, $params]) {
            $plan = implode(', ', array_map(static fn (mixed $detail): string => is_string($detail) ? $detail : '', array_column($this->connection->fetchAllAssociative('EXPLAIN QUERY PLAN '.$sql, $params), 'detail')));

            self::assertStringContainsString('USING INDEX ledger_event_subject', $plan);
            self::assertStringNotContainsString('SCAN', $plan);
        }
    }

    public function testARoundIdThatIsAPrefixOfAStartedRoundFindsNoStartedReviewer(): void
    {
        $this->startReviewer('r1', 'first');

        self::assertNull($this->pages()->startedReviewerOf('r', 'reviewer'));
    }

    public function testAStartStoredWithoutASubjectIsStillTheStartedReviewer(): void
    {
        $this->startReviewer('r1', 'before the upgrade', withSubject: false);
        $this->startReviewer('r10', 'other');

        $started = $this->pages()->startedReviewerOf('r1', 'reviewer');

        self::assertSame('before the upgrade', $started?->model);
        self::assertSame('correctness', $started->role);
    }

    public function testAStartWithASubjectWinsOverALaterStartWithoutOne(): void
    {
        $this->startReviewer('r1', 'with subject');
        $this->startReviewer('r1', 'without subject', withSubject: false);

        self::assertSame('with subject', $this->pages()->startedReviewerOf('r1', 'reviewer')?->model);
    }

    public function testTheLookupOfStartsWithoutASubjectSearchesTheSubjectIndexInsteadOfScanningTheLedger(): void
    {
        $this->startReviewer('r1', 'before the upgrade', withSubject: false);
        $this->startReviewer('r10', 'other');
        $this->selects->clear();

        self::assertSame('before the upgrade', $this->pages()->startedReviewerOf('r1', 'reviewer')?->model);

        self::assertCount(2, $this->selects->queries());
        foreach ($this->selects->queries() as [$sql, $params]) {
            $plan = implode(', ', array_map(static fn (mixed $detail): string => is_string($detail) ? $detail : '', array_column($this->connection->fetchAllAssociative('EXPLAIN QUERY PLAN '.$sql, $params), 'detail')));

            self::assertStringContainsString('USING INDEX ledger_event_subject', $plan);
            self::assertStringNotContainsString('SCAN', $plan);
        }
    }

    protected function setUp(): void
    {
        $this->selects = new LedgerSelects();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new Middleware($this->selects)]);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);

        $original = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $original);
        $defaultManager = $original->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $defaultManager);
        $manager = new EntityManager($this->connection, $defaultManager->getConfiguration());
        new SchemaTool($manager)->createSchema($manager->getMetadataFactory()->getAllMetadata());

        $registry = self::createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $this->ledger = new DoctrineLedger($registry, new MockClock('2026-11-22 12:00:00'));
        self::getContainer()->set(Ledger::class, $this->ledger);
    }

    private function startReviewer(string $round, string $model, bool $withSubject = true): void
    {
        $payload = json_encode(['round' => $round, 'reviewer' => 'reviewer', 'role' => 'correctness', 'tier' => 'standard', 'model' => $model], JSON_THROW_ON_ERROR);

        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, $payload, $withSubject ? $round : null))->succeeded());
    }

    private function pages(): LedgerReviewPages
    {
        $pages = self::getContainer()->get(LedgerReviewPages::class);
        self::assertInstanceOf(LedgerReviewPages::class, $pages);

        return $pages;
    }
}
