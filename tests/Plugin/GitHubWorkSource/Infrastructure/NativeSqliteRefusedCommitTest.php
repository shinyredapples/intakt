<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource\Infrastructure;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\ListingRows;
use Intakt\Plugin\GitHubWorkSource\Domain\ProjectHeldByAnotherWriter;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\SyncState;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\IntakeStore;
use Intakt\Tests\Plugin\GitHubWorkSource\Issues;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

// DATABASE_URL may name DBAL's native SQLite3 driver (sqlite3://), which also keeps its transaction open after a refused COMMIT.
#[Group('persistence')]
#[CoversClass(IntakeStore::class)]
#[UsesClass(IntakeValidator::class)]
#[UsesClass(StoredIssue::class)]
#[UsesClass(ListingRows::class)]
#[UsesClass(SyncState::class)]
#[UsesClass(ProjectHeldByAnotherWriter::class)]
#[UsesClass(ItemReading::class)]
#[UsesClass(IssueReferences::class)]
final class NativeSqliteRefusedCommitTest extends KernelTestCase
{
    public function testACommitRefusedOnTheNativeSqliteDriverRollsBackItsGenerationAndTheNextWriteSucceeds(): void
    {
        if (!extension_loaded('sqlite3')) {
            self::markTestSkipped('The sqlite3 extension is not loaded.');
        }

        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $file = (string) tempnam(sys_get_temp_dir(), 'intake');
        $first = DriverManager::getConnection(['driver' => 'sqlite3', 'path' => $file]);
        $second = DriverManager::getConnection(['driver' => 'sqlite3', 'path' => $file]);
        try {
            $first->executeStatement('PRAGMA busy_timeout=0');
            $events = new EventManager();
            $manager = new EntityManager($first, $entities->getConfiguration(), $events);
            new SchemaTool($manager)->createSchema([$manager->getClassMetadata(IntakeValidator::class), $manager->getClassMetadata(StoredIssue::class)]);
            $reading = new readonly class($second) {
                public function __construct(private Connection $other)
                {
                }

                // Held open, because SQLite refuses the store's COMMIT as busy while another worker's read transaction lasts.
                public function onFlush(): void
                {
                    $this->other->beginTransaction();
                    $this->other->fetchOne('SELECT COUNT(*) FROM work_source_validator');
                }
            };
            $events->addEventListener([Events::onFlush], $reading);
            $store = new IntakeStore($manager);

            $held = $this->heldWhileRecording($store, $store->syncStateOf('alpha'));
            $second->rollBack();
            $events->removeEventListener([Events::onFlush], $reading);

            self::assertInstanceOf(ProjectHeldByAnotherWriter::class, $held);
            self::assertSame(0, $store->syncStateOf('alpha')->generation);
            self::assertTrue($store->recordIfStill('alpha', $store->syncStateOf('alpha'), new ListingRows([$this->stored()], 'after')));
            self::assertSame(1, $store->syncStateOf('alpha')->generation);
        } finally {
            $second->close();
            $first->close();
            unlink($file);
        }
    }

    public function testAFirstWriteThatLosesOnTheNativeSqliteDriverWithNoTransactionLeftOpenAnswersFalse(): void
    {
        if (!extension_loaded('sqlite3')) {
            self::markTestSkipped('The sqlite3 extension is not loaded.');
        }

        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $file = (string) tempnam(sys_get_temp_dir(), 'intake');
        $first = DriverManager::getConnection(['driver' => 'sqlite3', 'path' => $file]);
        $second = DriverManager::getConnection(['driver' => 'sqlite3', 'path' => $file]);
        try {
            $first->fetchOne('PRAGMA journal_mode=WAL');
            $events = new EventManager();
            $losing = new EntityManager($first, $entities->getConfiguration(), $events);
            new SchemaTool($losing)->createSchema([$losing->getClassMetadata(IntakeValidator::class), $losing->getClassMetadata(StoredIssue::class)]);
            $winner = new IntakeStore(new EntityManager($second, $entities->getConfiguration(), new EventManager()));
            $events->addEventListener([Events::onFlush], new readonly class($winner, $this->stored()) {
                public function __construct(private IntakeStore $winner, private StoredIssue $newer)
                {
                }

                public function onFlush(): void
                {
                    (void) $this->winner->recordIfStill('alpha', $this->winner->syncStateOf('alpha'), new ListingRows([$this->newer], 'W/"newer"'));
                }
            });
            $loser = new IntakeStore($losing);

            $written = $loser->recordIfStill('alpha', $loser->syncStateOf('alpha'), new ListingRows([$this->stored()], null));

            self::assertFalse($written);
            self::assertSame(0, $first->getTransactionNestingLevel());
            self::assertSame('W/"newer"', $loser->syncStateOf('alpha')->validator);
        } finally {
            $second->close();
            $first->close();
            unlink($file);
        }
    }

    private function heldWhileRecording(IntakeStore $store, SyncState $readBefore): ?ProjectHeldByAnotherWriter
    {
        try {
            (void) $store->recordIfStill('alpha', $readBefore, new ListingRows([$this->stored()], null));
        } catch (ProjectHeldByAnotherWriter $held) {
            return $held;
        }

        return null;
    }

    private function stored(): StoredIssue
    {
        $listed = Issues::task(7);
        $references = new IssueReferences();

        return StoredIssue::of('alpha', new ItemReading($listed, $references->waitsFor($listed->body), $references->unreadableWaits($listed->body), $references->closedBy($listed->body)));
    }
}
