<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\OwnerNotice;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Domain\OutageNoticeRecord;
use Intakt\Core\Budget\Domain\WindowReadingRecord;
use Intakt\Core\Budget\Infrastructure\DoctrineOwnerNotices;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;

#[Group('persistence')]
#[CoversClass(DoctrineOwnerNotices::class)]
#[CoversClass(OwnerNotice::class)]
#[UsesClass(OutageNoticeRecord::class)]
#[UsesClass(WindowReadingRecord::class)]
#[UsesClass(WindowReading::class)]
#[UsesClass(Usage::class)]
#[UsesClass(Account::class)]
final class DoctrineOwnerNoticesTest extends KernelTestCase
{
    private EntityManagerInterface $entities;
    private DateTimeImmutable $noticedAt;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->noticedAt = new MockClock('2026-11-02 12:00:00 UTC')->now();
    }

    public function testWithNoNoticeStoredNothingIsInForce(): void
    {
        self::assertSame([], $this->ownerNotices()->inForce());
    }

    public function testANoticeWithoutALaterReadingOfItsAccountIsInForce(): void
    {
        $this->notice($this->claude('main'));

        self::assertEquals(
            [new OwnerNotice($this->claude('main'), $this->noticedAt->modify('-4 hours'), $this->noticedAt)],
            $this->ownerNotices()->inForce(),
        );
    }

    public function testANoticeFollowedByAReadingOfItsAccountIsNoLongerInForceAndStaysStored(): void
    {
        $this->notice($this->claude('main'));
        $this->reading($this->claude('main'), '+1 minute');

        self::assertSame([], $this->ownerNotices()->inForce());
        self::assertCount(1, $this->entities->getRepository(OutageNoticeRecord::class)->findAll());
    }

    public function testAReadingTakenAtTheMomentOfTheNoticeEndsIt(): void
    {
        $this->notice($this->claude('main'));
        $this->reading($this->claude('main'), '+0 seconds');

        self::assertSame([], $this->ownerNotices()->inForce());
    }

    public function testAReadingTakenBeforeTheNoticeKeepsItInForce(): void
    {
        $this->notice($this->claude('main'));
        $this->reading($this->claude('main'), '-1 second');

        self::assertCount(1, $this->ownerNotices()->inForce());
    }

    public function testAReadingOfTheSameAccountNameAtAnotherProviderKeepsTheNoticeInForce(): void
    {
        $this->notice($this->claude('main'));
        $this->reading(new Account(Provider::Anthropic, 'main'), '+1 minute');

        self::assertCount(1, $this->ownerNotices()->inForce());
    }

    public function testAReadingOfAnotherAccountAtTheSameProviderKeepsTheNoticeInForce(): void
    {
        $this->notice($this->claude('main'));
        $this->reading($this->claude('team'), '+1 minute');

        self::assertCount(1, $this->ownerNotices()->inForce());
    }

    public function testNoticesInForceAreListedInTheOrderTheyWereNoticed(): void
    {
        $this->notice($this->claude('team'));
        $this->notice($this->claude('main'));

        self::assertSame(
            ['team', 'main'],
            array_map(static fn (OwnerNotice $notice): string => $notice->account->name, $this->ownerNotices()->inForce()),
        );
    }

    private function ownerNotices(): DoctrineOwnerNotices
    {
        return new DoctrineOwnerNotices($this->entities);
    }

    private function notice(Account $account): void
    {
        $this->entities->persist(new OutageNoticeRecord($account, $this->noticedAt->modify('-4 hours'), $this->noticedAt));
        $this->entities->flush();
    }

    private function reading(Account $account, string $sinceTheNotice): void
    {
        $reading = new WindowReading($account, UsageWindow::FiveHour, new Usage(12.0, null));
        $this->entities->persist(new WindowReadingRecord($reading, $this->noticedAt->modify($sinceTheNotice)));
        $this->entities->flush();
    }

    private function claude(string $name): Account
    {
        return new Account(Provider::ClaudeCode, $name);
    }
}
