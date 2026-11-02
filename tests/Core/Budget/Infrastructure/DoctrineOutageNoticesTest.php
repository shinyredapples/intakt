<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Domain\OutageNoticeRecord;
use Intakt\Core\Budget\Infrastructure\DoctrineOutageNotices;
use Intakt\Tests\Application\EmptyDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[Group('persistence')]
#[CoversClass(DoctrineOutageNotices::class)]
#[CoversClass(OutageNoticeRecord::class)]
#[UsesClass(Account::class)]
final class DoctrineOutageNoticesTest extends KernelTestCase
{
    private EntityManagerInterface $entities;
    private MockClock $clock;
    private LockFactory $locks;
    private DateTimeImmutable $lastReading;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->clock = new MockClock('2026-11-02 12:00:00 UTC');
        $this->locks = new LockFactory(new InMemoryStore());
        $this->lastReading = $this->clock->now()->modify('-4 hours');
    }

    public function testAnOutageIsNoticedToTheOwnerOnce(): void
    {
        $notices = $this->noticesTelling(1);

        $notices->noticeOnce($this->claude('main'), $this->lastReading);
        $this->clock->sleep(3600);
        $notices->noticeOnce($this->claude('main'), $this->lastReading);
    }

    public function testTheNoticeNamesTheAccountAndTheLastReading(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(self::anything(), ['account' => 'claude_code.main', 'since' => '2026-11-02T08:00:00+00:00']);

        new DoctrineOutageNotices($this->entities, $this->clock, $this->locks, $logger)->noticeOnce($this->claude('main'), $this->lastReading);
    }

    public function testAnotherWorkerDoesNotNoticeAnOutageAlreadyNoticed(): void
    {
        $this->noticesTelling(1)->noticeOnce($this->claude('main'), $this->lastReading);

        $this->noticesTelling(0)->noticeOnce($this->claude('main'), $this->lastReading);
    }

    public function testAWorkerThatFindsTheNoticeLockTakenLeavesTheNoticeToItsHolder(): void
    {
        $held = $this->locks->createLock('budget-outage-notice-claude_code.main');
        self::assertTrue($held->acquire(false));

        $this->noticesTelling(0)->noticeOnce($this->claude('main'), $this->lastReading);
        $held->release();
        $this->noticesTelling(1)->noticeOnce($this->claude('main'), $this->lastReading);
    }

    public function testANewOutageAfterANewReadingIsNoticedAgain(): void
    {
        $notices = $this->noticesTelling(2);

        $notices->noticeOnce($this->claude('main'), $this->lastReading);
        $notices->noticeOnce($this->claude('main'), $this->lastReading->modify('+1 hour'));
    }

    public function testAnOutageOfAnotherAccountIsNoticedOnItsOwn(): void
    {
        $notices = $this->noticesTelling(3);

        $notices->noticeOnce($this->claude('main'), $this->lastReading);
        $notices->noticeOnce($this->claude('team'), $this->lastReading);
        $notices->noticeOnce(new Account(Provider::Anthropic, 'main'), $this->lastReading);
    }

    private function noticesTelling(int $times): DoctrineOutageNotices
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly($times))->method('warning');

        return new DoctrineOutageNotices($this->entities, $this->clock, $this->locks, $logger);
    }

    private function claude(string $name): Account
    {
        return new Account(Provider::ClaudeCode, $name);
    }
}
