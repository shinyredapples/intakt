<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Domain\EventRecord;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use Intakt\Core\Ledger\Infrastructure\UtcDateTimeImmutableType;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;

// On MySQL and MariaDB the subject column stores latin1 while the connection sends utf8mb4, because only they let a column hold another character set; other engines run the same reads on their own column.
#[Group('persistence')]
#[CoversClass(DoctrineLedger::class)]
#[UsesClass(EventRecord::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(UtcDateTimeImmutableType::class)]
final class DoctrineLedgerOnANonAsciiSubjectTest extends KernelTestCase
{
    private DoctrineLedger $ledger;

    public function testTheNewestOfKindAboutReadFindsAnExactSubjectWithALetterOutsideAscii(): void
    {
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, 'exact', 'café'))->succeeded());

        self::assertSame('exact', $this->ledger->newestOfKindAbout(LedgerEventKind::ReviewerStarted, 'café')?->event->payload);
    }

    public function testTheNewestOfKindAboutReadSkipsANewerSubjectThatDiffersOnlyInCase(): void
    {
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, 'exact', 'café'))->succeeded());
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, 'case variant', 'CAFÉ'))->succeeded());

        self::assertSame('exact', $this->ledger->newestOfKindAbout(LedgerEventKind::ReviewerStarted, 'café')?->event->payload);
    }

    protected function setUp(): void
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $manager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $tool = new SchemaTool($manager);
        $tool->dropDatabase();
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $connection = $manager->getConnection();
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $connection->executeStatement('ALTER TABLE ledger_event MODIFY subject VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL');
        }

        $this->ledger = new DoctrineLedger($registry, new MockClock('2026-11-22 12:00:00'));
    }
}
