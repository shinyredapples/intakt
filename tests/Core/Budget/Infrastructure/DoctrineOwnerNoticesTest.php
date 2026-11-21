<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Budget\Domain\OutageNoticeRecord;
use Intakt\Core\Budget\Domain\OutageWatch;
use Intakt\Core\Budget\Domain\UsageReadingsStayedAway;
use Intakt\Core\Budget\Domain\WindowReadingRecord;
use Intakt\Core\Budget\Infrastructure\DoctrineMeasuredUse;
use Intakt\Core\Budget\Infrastructure\DoctrineOutageNotices;
use Intakt\Core\Budget\Infrastructure\DoctrineOwnerNotices;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[Group('persistence')]
#[CoversClass(DoctrineOwnerNotices::class)]
#[UsesClass(DoctrineMeasuredUse::class)]
#[UsesClass(DoctrineOutageNotices::class)]
#[UsesClass(OutageWatch::class)]
#[UsesClass(UsageReadingsStayedAway::class)]
#[UsesClass(WindowUse::class)]
#[UsesClass(OutageNotice::class)]
#[UsesClass(OutageNoticeRecord::class)]
#[UsesClass(Account::class)]
#[UsesClass(Usage::class)]
#[UsesClass(WindowReading::class)]
#[UsesClass(WindowReadingRecord::class)]
final class DoctrineOwnerNoticesTest extends KernelTestCase
{
    private EntityManagerInterface $entities;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
    }

    public function testOutageNoticesAreListedOldestFirst(): void
    {
        $this->store('later', new DateTimeImmutable('2026-11-02T12:00:00+00:00'));
        $this->store('first', new DateTimeImmutable('2026-11-02T09:00:00+00:00'));

        $accounts = array_map(static fn (OutageNotice $notice): string => $notice->account->name, $this->notices()->stillRunningOldestFirst());

        self::assertSame(['first', 'later'], $accounts);
    }

    public function testNoticesNoticedTogetherKeepTheOrderTheyWereStoredIn(): void
    {
        $this->store('b', new DateTimeImmutable('2026-11-02T09:00:00+00:00'));
        $this->store('a', new DateTimeImmutable('2026-11-02T09:00:00+00:00'));

        $accounts = array_map(static fn (OutageNotice $notice): string => $notice->account->name, $this->notices()->stillRunningOldestFirst());

        self::assertSame(['b', 'a'], $accounts);
    }

    public function testANoticeCarriesItsAccountAndTheLastReading(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T09:00:00+00:00'));

        $notice = $this->notices()->stillRunningOldestFirst()[0];

        self::assertSame('claude_code.main', $notice->account->key());
        self::assertSame('2026-11-02 08:00:00', $notice->lastReadingAt->format('Y-m-d H:i:s'));
    }

    public function testNoNoticeGivesAnEmptyList(): void
    {
        self::assertSame([], $this->notices()->stillRunningOldestFirst());
    }

    public function testAReadingOfTheAccountAfterTheOutageTakesItsNoticeOffTheList(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->read('main', new DateTimeImmutable('2026-11-02T12:00:00+00:00'));

        self::assertSame([], $this->notices()->stillRunningOldestFirst());
    }

    public function testANoticeTakenOffTheListStaysStored(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->read('main', new DateTimeImmutable('2026-11-02T12:00:00+00:00'));

        $this->notices()->stillRunningOldestFirst();

        self::assertCount(1, $this->entities->getRepository(OutageNoticeRecord::class)->findAll());
    }

    // A fresh reading can land after the watch read the old snapshot but before it stamps the notice, so the notice's own moment is later than that reading.
    public function testAReadingThatLandsWhileTheOutageIsBeingNoticedTakesItOffTheList(): void
    {
        $account = new Account(Provider::ClaudeCode, 'main');
        $clock = new MockClock('2026-11-02 08:00:00 UTC');
        $use = new DoctrineMeasuredUse($this->entities, $clock);
        $reading = new WindowReading($account, UsageWindow::FiveHour, new Usage(12.0, null));
        $use->record($reading);
        $clock->modify('+4 hours');
        $snapshots = self::createStub(MeasuredUse::class);
        $snapshots->method('of')->willReturnCallback(
            static function (Account $account, UsageWindow $window) use ($clock, $reading, $use): ?WindowUse {
                $snapshot = $use->of($account, $window);
                if (UsageWindow::FiveHour === $window) {
                    $use->record($reading);
                    $clock->modify('+1 second');
                }

                return $snapshot;
            },
        );
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(UsageReadingsStayedAway::class, function (UsageReadingsStayedAway $away): void {
            $this->entities->persist(new OutageNoticeRecord($away->account, $away->lastReadingAt, $away->seenAt));
            $this->entities->flush();
        });

        new OutageWatch($snapshots, $clock, $dispatcher)->watch($account);

        self::assertCount(1, $this->entities->getRepository(OutageNoticeRecord::class)->findAll());
        self::assertSame([], $this->notices()->stillRunningOldestFirst());
    }

    // A slow writer stamps its reading before a fresh one lands but stores it after, so cleaning up by position removes the fresh one.
    public function testAReadingStoredLateStillTakesTheNoticeOffTheList(): void
    {
        $account = new Account(Provider::ClaudeCode, 'main');
        $clock = new MockClock('2026-11-02 08:00:00 UTC');
        $use = new DoctrineMeasuredUse($this->entities, $clock);
        $use->record(new WindowReading($account, UsageWindow::FiveHour, new Usage(12.0, null)));
        $clock->modify('+4 hours');
        $this->entities->persist(new OutageNoticeRecord($account, new DateTimeImmutable('2026-11-02T08:00:00+00:00'), $clock->now()));
        $this->entities->flush();
        $reading = new WindowReading($account, UsageWindow::FiveHour, new Usage(12.0, null));
        $slowClock = self::createStub(ClockInterface::class);
        $slowClock->method('now')->willReturnCallback(static function () use ($clock, $use, $reading): DateTimeImmutable {
            $stampedAt = $clock->now()->modify('-1 second');
            $clock->modify('+1 second');
            $use->record($reading);

            return $stampedAt;
        });

        new DoctrineMeasuredUse($this->entities, $slowClock)->record($reading);

        self::assertSame([], $this->notices()->stillRunningOldestFirst());
    }

    // Each window's age is sampled at its own clock reading, so a pause between the lookups must not make the older reading look newer.
    public function testAnOutageNoticedWhileTheClockAdvancesBetweenWindowLookupsIsStoredOnceAndListed(): void
    {
        $account = new Account(Provider::ClaudeCode, 'main');
        $clock = new MockClock('2026-11-02 08:00:00 UTC');
        $use = new DoctrineMeasuredUse($this->entities, $clock);
        $use->record(new WindowReading($account, UsageWindow::FiveHour, new Usage(12.0, null)));
        $clock->sleep(1);
        $use->record(new WindowReading($account, UsageWindow::Weekly, new Usage(5.0, null)));
        $clock->modify('+4 hours');
        $slowLookups = self::createStub(MeasuredUse::class);
        $slowLookups->method('of')->willReturnCallback(static function (Account $account, UsageWindow $window) use ($clock, $use): ?WindowUse {
            $snapshot = $use->of($account, $window);
            $clock->sleep(2);

            return $snapshot;
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(self::anything(), ['account' => 'claude_code.main', 'since' => '2026-11-02T08:00:01+00:00']);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(UsageReadingsStayedAway::class, new DoctrineOutageNotices($this->entities, new LockFactory(new InMemoryStore()), $logger)->noticeOnce(...));

        new OutageWatch($slowLookups, $clock, $dispatcher)->watch($account);

        self::assertCount(1, $this->entities->getRepository(OutageNoticeRecord::class)->findAll());
        self::assertCount(1, $this->notices()->stillRunningOldestFirst());
    }

    public function testOfTwoOutagesOfOneAccountWithAReadingBetweenThemOnlyTheLaterNoticeIsListed(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->read('main', new DateTimeImmutable('2026-11-02T12:00:00+00:00'));
        $this->store('main', new DateTimeImmutable('2026-11-02T15:00:00+00:00'), new DateTimeImmutable('2026-11-02T12:00:00+00:00'));

        $noticedAt = array_map(static fn (OutageNotice $notice): string => $notice->noticedAt->format('H:i'), $this->notices()->stillRunningOldestFirst());

        self::assertSame(['15:00'], $noticedAt);
    }

    public function testTheReadingTheOutageStartedFromKeepsItsNoticeListed(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->read('main', new DateTimeImmutable('2026-11-02T08:00:00+00:00'));

        self::assertCount(1, $this->notices()->stillRunningOldestFirst());
    }

    public function testAReadingOfAnotherAccountKeepsTheNoticeListed(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->read('other', new DateTimeImmutable('2026-11-02T12:00:00+00:00'));

        self::assertCount(1, $this->notices()->stillRunningOldestFirst());
    }

    // An account is its provider and its name together, so the same name at another provider is another account.
    public function testAReadingOfTheSameAccountNameAtAnotherProviderKeepsTheNoticeListed(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->read('main', new DateTimeImmutable('2026-11-02T12:00:00+00:00'), Provider::Codex);

        self::assertCount(1, $this->notices()->stillRunningOldestFirst());
    }

    private function notices(): DoctrineOwnerNotices
    {
        return new DoctrineOwnerNotices($this->entities);
    }

    private function read(string $account, DateTimeImmutable $takenAt, Provider $provider = Provider::ClaudeCode): void
    {
        $this->entities->persist(new WindowReadingRecord(new WindowReading(new Account($provider, $account), UsageWindow::FiveHour, new Usage(10.0, null)), $takenAt));
        $this->entities->flush();
    }

    private function store(string $account, DateTimeImmutable $noticedAt, DateTimeImmutable $lastReadingAt = new DateTimeImmutable('2026-11-02T08:00:00+00:00')): void
    {
        $this->entities->persist(new OutageNoticeRecord(new Account(Provider::ClaudeCode, $account), $lastReadingAt, $noticedAt));
        $this->entities->flush();
    }
}
