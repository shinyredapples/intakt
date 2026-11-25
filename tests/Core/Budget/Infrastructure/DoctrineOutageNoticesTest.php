<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Domain\OutageNoticeRecord;
use Intakt\Core\Budget\Domain\UsageReadingsStayedAway;
use Intakt\Core\Budget\Infrastructure\AccountReadingLock;
use Intakt\Core\Budget\Infrastructure\DoctrineOutageNotices;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[Group('persistence')]
#[CoversClass(DoctrineOutageNotices::class)]
#[CoversClass(OutageNoticeRecord::class)]
#[UsesClass(AccountReadingLock::class)]
#[UsesClass(UsageReadingsStayedAway::class)]
#[UsesClass(Account::class)]
final class DoctrineOutageNoticesTest extends KernelTestCase
{
    private EntityManagerInterface $entities;
    private LockFactory $locks;
    private DateTimeImmutable $lastReading;

    public function testAnOutageIsNoticedToTheOwnerOnce(): void
    {
        $notices = $this->noticesTelling(1);

        $notices->noticeOnce($this->outageOf($this->claude('main'), $this->lastReading));
        $notices->noticeOnce($this->outageOf($this->claude('main'), $this->lastReading));
    }

    public function testTheNoticeNamesTheAccountAndTheLastReading(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(self::anything(), ['account' => 'claude_code.main', 'since' => '2026-11-02T08:00:00+00:00']);

        new DoctrineOutageNotices($this->entities, $this->locks, $logger, new AccountReadingLock($this->locks))->noticeOnce($this->outageOf($this->claude('main'), $this->lastReading));
    }

    public function testAWarningThatFailedIsTriedAgain(): void
    {
        $attempts = 0;
        $logger = self::createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function () use (&$attempts): void {
            if (1 === ++$attempts) {
                throw new RuntimeException('The warning could not be written.');
            }
        });
        $notices = new DoctrineOutageNotices($this->entities, $this->locks, $logger, new AccountReadingLock($this->locks));

        try {
            $notices->noticeOnce($this->outageOf($this->claude('main'), $this->lastReading));
        } catch (RuntimeException) {
        }
        $notices->noticeOnce($this->outageOf($this->claude('main'), $this->lastReading));

        self::assertSame(2, $attempts);
    }

    public function testAnotherWorkerDoesNotNoticeAnOutageAlreadyNoticed(): void
    {
        $this->noticesTelling(1)->noticeOnce($this->outageOf($this->claude('main'), $this->lastReading));

        $this->noticesTelling(0)->noticeOnce($this->outageOf($this->claude('main'), $this->lastReading));
    }

    public function testAWorkerThatFindsTheNoticeLockTakenLeavesTheNoticeToItsHolder(): void
    {
        $held = $this->locks->createLock('budget-outage-notice-claude_code.main');
        self::assertTrue($held->acquire(false));

        $this->noticesTelling(0)->noticeOnce($this->outageOf($this->claude('main'), $this->lastReading));
        $held->release();
        $this->noticesTelling(1)->noticeOnce($this->outageOf($this->claude('main'), $this->lastReading));
    }

    public function testANewOutageAfterANewReadingIsNoticedAgain(): void
    {
        $notices = $this->noticesTelling(2);

        $notices->noticeOnce($this->outageOf($this->claude('main'), $this->lastReading));
        $notices->noticeOnce($this->outageOf($this->claude('main'), $this->lastReading->modify('+1 hour')));
    }

    public function testAnOutageOfAnotherAccountIsNoticedOnItsOwn(): void
    {
        $notices = $this->noticesTelling(3);

        $notices->noticeOnce($this->outageOf($this->claude('main'), $this->lastReading));
        $notices->noticeOnce($this->outageOf($this->claude('team'), $this->lastReading));
        $notices->noticeOnce($this->outageOf(new Account(Provider::Anthropic, 'main'), $this->lastReading));
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->locks = new LockFactory(new InMemoryStore());
        $this->lastReading = new MockClock('2026-11-02 08:00:00 UTC')->now();
    }

    private function noticesTelling(int $times): DoctrineOutageNotices
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly($times))->method('warning');

        return new DoctrineOutageNotices($this->entities, $this->locks, $logger, new AccountReadingLock($this->locks));
    }

    private function claude(string $name): Account
    {
        return new Account(Provider::ClaudeCode, $name);
    }

    private function outageOf(Account $account, DateTimeImmutable $lastReadingAt): UsageReadingsStayedAway
    {
        return new UsageReadingsStayedAway($account, $lastReadingAt, $lastReadingAt->modify('+4 hours'));
    }
}
