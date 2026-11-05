<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Budget\Domain\ItemFailureRecord;
use Intakt\Core\Budget\Domain\SharedHoldRecord;
use Intakt\Core\Budget\Infrastructure\DoctrineHolds;
use Intakt\Core\Budget\Infrastructure\DoctrineInstallWideFailures;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordingLogger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use LogicException;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockStorageException;
use Throwable;

#[Group('persistence')]
#[CoversClass(DoctrineInstallWideFailures::class)]
#[CoversClass(ItemFailureRecord::class)]
#[UsesClass(DoctrineHolds::class)]
#[UsesClass(SharedHoldRecord::class)]
#[UsesClass(SharedHold::class)]
final class DoctrineInstallWideFailuresTest extends KernelTestCase
{
    private MockClock $clock;
    private EntityManagerInterface $entities;
    private DoctrineHolds $holds;
    private RecordingLogger $logger;
    private DoctrineInstallWideFailures $failures;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->clock = new MockClock('2026-11-05 10:00:00 UTC');
        $this->entities = $entities;
        $this->holds = new DoctrineHolds($entities, $this->clock);
        $this->logger = new RecordingLogger();
        $redactor = self::createStub(Redactor::class);
        $redactor->method('redact')->willReturnCallback(static fn (string $text): string => str_replace('secret-one', '[redacted]', str_replace('secret-two', '[redacted]', $text)));
        $this->failures = new DoctrineInstallWideFailures($entities, $this->holds, $this->clock, $redactor, $this->logger);
    }

    public function testOneItemFailingStartsNoFailureBackOff(): void
    {
        $this->failures->itemFailed('head 7', new RuntimeException('CI endpoint down'));

        self::assertSame([], $this->holds->sharedInEffect());
    }

    public function testOneItemFailingTwiceWithTheSameErrorStartsNoFailureBackOff(): void
    {
        $this->failures->itemFailed('head 7', new RuntimeException('CI endpoint down'));
        $this->failures->itemFailed('head 7', new RuntimeException('CI endpoint down'));

        self::assertSame([], $this->holds->sharedInEffect());
    }

    public function testTheSameErrorOnTwoItemsWithinAnHourStartsTheFailureBackOff(): void
    {
        $this->failures->itemFailed('head 7', new RuntimeException('CI endpoint down'));
        $this->clock->sleep(3599);

        $this->failures->itemFailed('head 8', new RuntimeException('CI endpoint down'));

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        self::assertSame('The same error on two items within an hour starts the failure back-off.', $this->logger->logged[0][0]);
    }

    public function testTheSameErrorOnTwoItemsAnHourApartStartsNoFailureBackOff(): void
    {
        $this->failures->itemFailed('head 7', new RuntimeException('CI endpoint down'));
        $this->clock->sleep(3600);

        $this->failures->itemFailed('head 8', new RuntimeException('CI endpoint down'));

        self::assertSame([], $this->holds->sharedInEffect());
    }

    public function testDifferentMessagesOnTwoItemsStartNoFailureBackOff(): void
    {
        $this->failures->itemFailed('head 7', new RuntimeException('commit aaa is not fetched'));
        $this->failures->itemFailed('head 8', new RuntimeException('commit bbb is not fetched'));

        self::assertSame([], $this->holds->sharedInEffect());
    }

    public function testTheSameMessageOfAnotherClassIsAnotherError(): void
    {
        $this->failures->itemFailed('head 7', new RuntimeException('down'));
        $this->failures->itemFailed('head 8', new LogicException('down'));

        self::assertSame([], $this->holds->sharedInEffect());
    }

    public function testMessagesThatDifferOnlyInARedactedCredentialAreTheSameError(): void
    {
        $this->failures->itemFailed('head 7', new RuntimeException('401 for key secret-one'));
        $this->failures->itemFailed('head 8', new RuntimeException('401 for key secret-two'));

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
    }

    public function testOnlyHashesOfTheItemAndTheErrorAreStored(): void
    {
        $this->failures->itemFailed('head 7', new RuntimeException('401 for key secret-one'));

        $row = $this->entities->getConnection()->fetchAssociative('SELECT item, error FROM budget_item_failure');
        self::assertSame(['item' => hash('sha256', 'head 7'), 'error' => hash('sha256', 'RuntimeException: 401 for key [redacted]')], $row);
    }

    public function testFailuresOlderThanAnHourAreDropped(): void
    {
        $this->failures->itemFailed('head 7', new RuntimeException('down'));
        $this->clock->sleep(3600);

        $this->failures->itemFailed('head 8', new RuntimeException('other'));

        self::assertSame(1, (int) $this->entities->getConnection()->fetchOne('SELECT COUNT(*) FROM budget_item_failure'));
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function failuresNoItemCanAvoid(): iterable
    {
        yield 'a lost database connection' => [new ConnectionLost(new DriverFailure('server has gone away'), null)];
        yield 'a lock store that fails' => [new LockStorageException('the lock store is down')];
        yield 'a lock store that fails while acquiring' => [new LockAcquiringException('Failed to acquire the "x" lock.')];
        yield 'a lock store failure wrapped by a forge' => [new RuntimeException('read failed', 0, new LockStorageException('the lock store is down'))];
    }

    #[DataProvider('failuresNoItemCanAvoid')]
    public function testAFailureNoItemCanAvoidStartsTheFailureBackOffOnItsFirstItem(Throwable $failure): void
    {
        $this->failures->itemFailed('head 7', $failure);

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        self::assertSame('A failure no item can avoid starts the failure back-off.', $this->logger->logged[0][0]);
    }

    public function testAHandOverThatFailedStartsTheFailureBackOffForTenMinutes(): void
    {
        $this->failures->handOverFailed(new RuntimeException('the ledger refused the line'));

        $this->clock->sleep(599);
        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        $this->clock->sleep(1);
        self::assertSame([], $this->holds->sharedInEffect());
        self::assertSame('A hand-over that failed starts the failure back-off.', $this->logger->logged[0][0]);
        self::assertSame('2026-11-05T10:10:00+00:00', $this->logger->logged[0][1]['until']);
    }
}
