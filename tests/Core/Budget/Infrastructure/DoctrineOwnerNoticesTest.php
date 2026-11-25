<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\EndedOutageNotice;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNoticesChanged;
use Intakt\Core\Budget\Contract\OwnerNoticesOfTheDay;
use Intakt\Core\Budget\Contract\ReadingOutcome;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Budget\Domain\OutageNoticeRecord;
use Intakt\Core\Budget\Domain\OutageWatch;
use Intakt\Core\Budget\Domain\UsageReadingsStayedAway;
use Intakt\Core\Budget\Domain\WindowReadingRecord;
use Intakt\Core\Budget\Infrastructure\AccountReadingLock;
use Intakt\Core\Budget\Infrastructure\DoctrineMeasuredUse;
use Intakt\Core\Budget\Infrastructure\DoctrineOutageNotices;
use Intakt\Core\Budget\Infrastructure\DoctrineOwnerNotices;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[Group('persistence')]
#[CoversClass(DoctrineOwnerNotices::class)]
#[CoversClass(DoctrineMeasuredUse::class)]
#[CoversClass(DoctrineOutageNotices::class)]
#[UsesClass(AccountReadingLock::class)]
#[UsesClass(OutageWatch::class)]
#[UsesClass(UsageReadingsStayedAway::class)]
#[UsesClass(WindowUse::class)]
#[UsesClass(OutageNotice::class)]
#[UsesClass(EndedOutageNotice::class)]
#[UsesClass(OwnerNoticesChanged::class)]
#[UsesClass(OwnerNoticesOfTheDay::class)]
#[UsesClass(OutageNoticeRecord::class)]
#[UsesClass(Account::class)]
#[UsesClass(Usage::class)]
#[UsesClass(WindowReading::class)]
#[UsesClass(WindowReadingRecord::class)]
final class DoctrineOwnerNoticesTest extends KernelTestCase
{
    private EntityManagerInterface $entities;
    private LockFactory $locks;
    private AccountReadingLock $accountLock;

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
        $use = new DoctrineMeasuredUse($this->entities, $clock, new EventDispatcher(), $this->accountLock);
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
        $use = new DoctrineMeasuredUse($this->entities, $clock, new EventDispatcher(), $this->accountLock);
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

        new DoctrineMeasuredUse($this->entities, $slowClock, new EventDispatcher(), $this->accountLock)->record($reading);

        self::assertSame([], $this->notices()->stillRunningOldestFirst());
    }

    // Each window's age is sampled at its own clock reading, so a pause between the lookups must not make the older reading look newer.
    public function testAnOutageNoticedWhileTheClockAdvancesBetweenWindowLookupsIsStoredOnceAndListed(): void
    {
        $account = new Account(Provider::ClaudeCode, 'main');
        $clock = new MockClock('2026-11-02 08:00:00 UTC');
        $use = new DoctrineMeasuredUse($this->entities, $clock, new EventDispatcher(), $this->accountLock);
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
        $dispatcher->addListener(UsageReadingsStayedAway::class, new DoctrineOutageNotices($this->entities, $this->locks, $logger, $this->accountLock)->noticeOnce(...));

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

    public function testAnOutageEndedByAReadingTakenTodayIsListedAsEndedToday(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->record('main', '2026-11-02 12:30:00');

        $ended = $this->notices()->endedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00'));

        self::assertCount(1, $ended);
        self::assertSame('claude_code.main', $ended[0]->account->key());
        self::assertSame('2026-11-02 12:30:00', $ended[0]->endedAt->format('Y-m-d H:i:s'));
    }

    public function testAnOutageEndedYesterdayIsNotListedToday(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->record('main', '2026-11-02 12:30:00');

        self::assertSame([], $this->notices()->endedOnTheDayOf(new DateTimeImmutable('2026-11-03T09:00:00+00:00')));
    }

    public function testANoticeStillRunningIsNotListedAsEnded(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));

        self::assertSame([], $this->notices()->endedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00')));
    }

    // The reading the outage started from is no newer reading, so it ends nothing.
    public function testAReadingTakenAtTheOutagesLastReadingDoesNotEndIt(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->record('main', '2026-11-02 08:00:00');

        self::assertSame([], $this->notices()->endedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00')));
        self::assertCount(1, $this->notices()->stillRunningOldestFirst());
    }

    // The day is the UTC day, so the last second before midnight and midnight itself fall on two days.
    public function testAReadingAtMidnightUtcEndsTheOutageOnTheNewDay(): void
    {
        $this->store('before', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->store('at', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->record('before', '2026-11-02 23:59:59');
        $this->record('at', '2026-11-03 00:00:00');

        self::assertSame(['before'], $this->namesEndedOnTheDayOf(new DateTimeImmutable('2026-11-02T12:00:00+00:00')));
        self::assertSame(['at'], $this->namesEndedOnTheDayOf(new DateTimeImmutable('2026-11-03T12:00:00+00:00')));
    }

    // A moment in another zone is still read as its UTC day, so the same outages are listed wherever the moment comes from.
    public function testAMomentInAnotherZoneIsReadAsItsUtcDay(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->record('main', '2026-11-02 23:30:00');

        self::assertCount(1, $this->notices()->endedOnTheDayOf(new DateTimeImmutable('2026-11-03T01:00:00+02:00')));
    }

    public function testOutagesEndedTodayAreListedNewestFirst(): void
    {
        $this->store('early', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->store('late', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->record('late', '2026-11-02 15:00:00');
        $this->record('early', '2026-11-02 12:00:00');

        self::assertSame(['late', 'early'], $this->namesEndedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00')));
    }

    // A slow writer stamps its reading before a later one but stores it after, so the outage ended at the earlier reading.
    public function testAnEarlierReadingStoredLateMovesTheEndToItsMoment(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->record('main', '2026-11-02 13:00:00');
        $this->record('main', '2026-11-02 12:30:00');

        self::assertSame('12:30', $this->notices()->endedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00'))[0]->endedAt->format('H:i'));
    }

    // The reading landed before the notice's row existed, so only the row's own look at the readings can end it.
    public function testAnOutageNoticedAfterAReadingAlreadyEndedItIsStoredAsEnded(): void
    {
        $this->read('main', new DateTimeImmutable('2026-11-02T12:00:00+00:00'));

        $this->outageNotices()->noticeOnce(new UsageReadingsStayedAway(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T12:00:01+00:00')));

        self::assertSame('12:00', $this->notices()->endedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00'))[0]->endedAt->format('H:i'));
    }

    public function testAnOutageNoticedWithNoNewerReadingIsNotStoredAsEnded(): void
    {
        $this->read('main', new DateTimeImmutable('2026-11-02T08:00:00+00:00'));
        $this->read('other', new DateTimeImmutable('2026-11-02T12:00:00+00:00'));

        $this->outageNotices()->noticeOnce(new UsageReadingsStayedAway(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T12:00:01+00:00')));

        self::assertSame([], $this->notices()->endedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00')));
    }

    // A notice stored before ends were kept has none, though a reading after it already ended its outage, so the next reading must not end it now.
    public function testAnOutageThatEndedBeforeEndsWereKeptIsNotListedAsEndedByALaterReading(): void
    {
        $this->store('main', new DateTimeImmutable('2026-10-01T11:00:00+00:00'), new DateTimeImmutable('2026-10-01T08:00:00+00:00'));
        $this->record('main', '2026-10-01 12:00:00');
        $this->entities->getConnection()->executeStatement('UPDATE budget_outage_notice SET ended_at = NULL');

        $this->record('main', '2026-11-21 09:00:00');

        self::assertSame([], $this->notices()->endedOnTheDayOf(new DateTimeImmutable('2026-11-21T20:00:00+00:00')));
        self::assertSame([], $this->notices()->stillRunningOldestFirst());
    }

    public function testAReadingFromBeforeTheOutageStillLetsTheNextReadingEndIt(): void
    {
        $this->read('main', new DateTimeImmutable('2026-11-02T07:00:00+00:00'));
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));

        $this->record('main', '2026-11-02 12:30:00');

        self::assertSame(['main'], $this->namesEndedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00')));
    }

    // The saved end alone keeps it off the list, because a late write or the cleanup of old readings can remove the reading that ended it.
    public function testANoticeWithASavedEndIsNotListedAsRunningOnceItsEndingReadingIsGone(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->record('main', '2026-11-02 12:00:00');
        $this->entities->getConnection()->executeStatement('DELETE FROM budget_window_reading');

        self::assertSame([], $this->notices()->stillRunningOldestFirst());
        self::assertSame(['main'], $this->namesEndedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00')));
    }

    // With no saved end, only the readings decide, so a notice whose ending reading is gone is listed again.
    public function testANoticeWithNoSavedEndIsListedAgainOnceItsEndingReadingIsGone(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->read('main', new DateTimeImmutable('2026-11-02T12:00:00+00:00'));
        $this->entities->getConnection()->executeStatement('DELETE FROM budget_window_reading');

        self::assertCount(1, $this->notices()->stillRunningOldestFirst());
    }

    // The reading lands after the notice looked at the readings and before its row exists, so neither the reading nor that first look can end it.
    public function testAReadingThatLandsWhileTheNoticeIsStoredEndsIt(): void
    {
        $this->read('main', new DateTimeImmutable('2026-11-02T08:00:00+00:00'));
        $record = $this->record(...);
        $landing = new class($record) {
            private bool $landed = false;

            /** @param Closure(string, string): void $record */
            public function __construct(private readonly Closure $record)
            {
            }

            public function prePersist(PrePersistEventArgs $args): void
            {
                if ($this->landed || !$args->getObject() instanceof OutageNoticeRecord) {
                    return;
                }
                $this->landed = true;
                ($this->record)('main', '2026-11-02 12:00:00');
            }
        };
        $this->entities->getEventManager()->addEventListener(Events::prePersist, $landing);

        try {
            $this->outageNotices()->noticeOnce(new UsageReadingsStayedAway(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T12:00:01+00:00')));
        } finally {
            $this->entities->getEventManager()->removeEventListener(Events::prePersist, $landing);
        }

        self::assertSame(['main'], $this->namesEndedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00')));
        self::assertSame([], $this->notices()->stillRunningOldestFirst());
    }

    // The end is saved in one transaction with the reading, so a failed end leaves the earlier reading as the latest and the notice waiting.
    public function testAReadingWhoseOutageEndFailsToSaveIsRolledBackAndTheNoticeStaysWaiting(): void
    {
        $this->record('main', '2026-11-02 08:00:00');
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));

        $this->recordWhileTheEndFails($this->measuredUseWhoseFirstEndFails(new MockClock('2026-11-02 12:00:00 UTC'), new EventDispatcher()));

        self::assertSame('08:00', $this->measuredUseAt('2026-11-02 12:00:00')->of(new Account(Provider::ClaudeCode, 'main'), UsageWindow::FiveHour)?->takenAt->format('H:i'));
        self::assertCount(1, $this->notices()->stillRunningOldestFirst());
        self::assertSame([], $this->notices()->endedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00')));
    }

    // The same worker goes on after the failure, so the reading it rolled back must not stand in for the one it stores next.
    public function testTheNextReadingAfterAFailedEndEndsTheNotice(): void
    {
        $this->record('main', '2026-11-02 08:00:00');
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $clock = new MockClock('2026-11-02 12:00:00 UTC');
        $worker = $this->measuredUseWhoseFirstEndFails($clock, new EventDispatcher());
        $this->recordWhileTheEndFails($worker);
        $clock->modify('+30 minutes');

        self::assertSame(ReadingOutcome::Recorded, $worker->record($this->readingOf('main')));

        self::assertSame('12:30', $worker->of(new Account(Provider::ClaudeCode, 'main'), UsageWindow::FiveHour)?->takenAt->format('H:i'));
        self::assertSame([], $this->notices()->stillRunningOldestFirst());
        self::assertSame('12:30', $this->notices()->endedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00'))[0]->endedAt->format('H:i'));
    }

    public function testAReadingWhoseOutageEndFailsTellsNoListener(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $heard = 0;
        $events = new EventDispatcher();
        $events->addListener(OwnerNoticesChanged::class, static function () use (&$heard): void {
            ++$heard;
        });

        $this->recordWhileTheEndFails($this->measuredUseWhoseFirstEndFails(new MockClock('2026-11-02 12:00:00 UTC'), $events));

        self::assertSame(0, $heard);
    }

    // A listener reads the notices again, so it must hear only once the end is committed and visible to every reader.
    public function testAReadingThatEndsAnOutageTellsListenersAfterItsCommit(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $heard = [];
        $events = new EventDispatcher();
        $events->addListener(OwnerNoticesChanged::class, function () use (&$heard): void {
            $heard[] = [$this->entities->getConnection()->isTransactionActive(), count($this->notices()->endedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00')))];
        });

        $this->measuredUseAt('2026-11-02 12:00:00', $events)->record($this->readingOf('main'));

        self::assertSame([[false, 1]], $heard);
    }

    public function testAReadingThatEndsNoOutageTellsNoListener(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $heard = 0;
        $events = new EventDispatcher();
        $events->addListener(OwnerNoticesChanged::class, static function () use (&$heard): void {
            ++$heard;
        });

        $this->measuredUseAt('2026-11-02 12:00:00', $events)->record($this->readingOf('other'));

        self::assertSame(0, $heard);
    }

    // No reading of the account is stored before the late one, so no earlier reading shows the outage ended; the fresher reading stored first did, before ends were kept.
    public function testAnEarlierReadingStoredLateGivesNoEndToANoticeFromBeforeEndsWereKept(): void
    {
        $this->store('main', new DateTimeImmutable('2026-10-01T11:00:00+00:00'), new DateTimeImmutable('2026-10-01T08:00:00+00:00'));
        $this->read('main', new DateTimeImmutable('2026-11-21T09:00:00+00:00'));

        $this->record('main', '2026-11-21 08:59:00');

        self::assertSame([], $this->notices()->endedOnTheDayOf(new DateTimeImmutable('2026-11-21T20:00:00+00:00')));
        self::assertSame([], $this->notices()->stillRunningOldestFirst());
    }

    // The writer starts at the driver's commit, because on a read-committed engine it would otherwise see no recovery and leave the notice without an end; it is refused the shared lock there, then runs after the commit and gives the notice the reading's moment.
    public function testTheOnlyRecoveryReadingHoldsTheAccountsLockUntilItCommitsAndThenGivesTheNoticeItsMomentAsTheEnd(): void
    {
        $shared = new AccountReadingLock(new LockFactory(new RefusingToWaitStore(new InMemoryStore())));
        $outage = new UsageReadingsStayedAway(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $notices = new DoctrineOutageNotices($this->entities, $this->locks, self::createStub(LoggerInterface::class), $shared);
        $refused = false;
        $hook = $this->atTheDriversCommit(function () use (&$refused, $notices, $outage): void {
            try {
                $notices->noticeOnce($outage);
            } catch (LockConflictedException) {
                $refused = true;
            }
        });
        $connection = DriverManager::getConnection($this->entities->getConnection()->getParams(), new Configuration()->setMiddlewares([new Middleware($hook)]));
        $reading = new DoctrineMeasuredUse(new EntityManager($connection, $this->entities->getConfiguration()), new MockClock('2026-11-02 12:00:00 UTC'), new EventDispatcher(), $shared);

        $reading->record($this->readingOf('main'));
        $notices->noticeOnce($outage);

        self::assertTrue($refused);
        self::assertSame(['main'], $this->namesEndedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00')));
        self::assertSame('12:00', $this->notices()->endedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00'))[0]->endedAt->format('H:i'));
    }

    public function testTheLockOfAReadingIsGivenBackOnceItIsCommitted(): void
    {
        new DoctrineMeasuredUse($this->entities, new MockClock('2026-11-02 12:00:00 UTC'), new EventDispatcher(), $this->accountLock)->record($this->readingOf('main'));

        $lock = $this->accountLock->of(new Account(Provider::ClaudeCode, 'main'));

        self::assertTrue($lock->acquire(false));
        $lock->release();
    }

    public function testAReadingOfOneAccountDoesNotHoldTheLockOfAnother(): void
    {
        $otherFree = null;
        $hook = $this->afterTheReadingsEndOfOutages(function () use (&$otherFree): void {
            $other = $this->accountLock->of(new Account(Provider::ClaudeCode, 'other'));
            $otherFree = $other->acquire(false);
            $other->release();
        });
        $connection = DriverManager::getConnection($this->entities->getConnection()->getParams(), new Configuration()->setMiddlewares([new Middleware($hook)]));

        new DoctrineMeasuredUse(new EntityManager($connection, $this->entities->getConfiguration()), new MockClock('2026-11-02 12:00:00 UTC'), new EventDispatcher(), $this->accountLock)->record($this->readingOf('main'));

        self::assertTrue($otherFree);
    }

    // One process cannot show a writer blocked on the other's commit, so the order is shown in two parts: the reading holds the lock between its update and commit (above), and the notice writer waits for that same lock before its last look (here).
    public function testTheNoticeWriterWaitsForTheAccountsLockBeforeItsLastLook(): void
    {
        $waited = [];
        $store = self::createStub(BlockingStoreInterface::class);
        $store->method('waitAndSave')->willReturnCallback(static function (Key $key) use (&$waited): void {
            $waited[] = (string) $key;
        });
        $notices = new DoctrineOutageNotices($this->entities, $this->locks, self::createStub(LoggerInterface::class), new AccountReadingLock(new LockFactory($store)));

        $notices->noticeOnce(new UsageReadingsStayedAway(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00')));

        self::assertSame(['budget-account-reading-'.new Account(Provider::ClaudeCode, 'main')->key()], $waited);
    }

    public function testAReadingInsideACallersTransactionLeavesNoAccountLockBehind(): void
    {
        $account = new Account(Provider::ClaudeCode, 'main');
        $connection = $this->entities->getConnection();
        $connection->beginTransaction();
        new DoctrineMeasuredUse($this->entities, new MockClock('2026-11-02 12:00:00 UTC'), new EventDispatcher(), $this->accountLock)->record($this->readingOf('main'));

        $takenInside = $this->accountLock->of($account)->acquire(false);
        $connection->rollBack();

        self::assertTrue($takenInside);
    }

    public function testAReadingWhoseEndFailsGivesTheAccountsLockBack(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->recordWhileTheEndFails($this->measuredUseWhoseFirstEndFails(new MockClock('2026-11-02 12:00:00 UTC'), new EventDispatcher()));

        self::assertTrue($this->accountLock->of(new Account(Provider::ClaudeCode, 'main'))->acquire(false));
    }

    // An end sent after the commit could fail with the reading already kept and leave the notice with its later end, so the reading ends the outage only inside its transaction.
    public function testAnEarlierReadingStoredLateGivesTheNoticeItsMomentWithoutAnEndAfterItsCommit(): void
    {
        $this->outageNotices()->noticeOnce(new UsageReadingsStayedAway(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00')));
        $this->record('main', '2026-11-02 12:00:00');
        $heard = 0;
        $events = new EventDispatcher();
        $events->addListener(OwnerNoticesChanged::class, static function () use (&$heard): void {
            ++$heard;
        });
        $connection = DriverManager::getConnection($this->entities->getConnection()->getParams(), new Configuration()->setMiddlewares([new OutageEndAfterTheCommitFails()]));
        $late = new DoctrineMeasuredUse(new EntityManager($connection, $this->entities->getConfiguration()), new MockClock('2026-11-02 11:59:00 UTC'), $events, $this->accountLock);

        $late->record($this->readingOf('main'));

        self::assertSame(1, $heard);
        self::assertSame('11:59', $this->notices()->endedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00'))[0]->endedAt->format('H:i'));
    }

    // The caller's rollback would erase the end, so a reading recorded inside its transaction tells nobody.
    public function testAReadingRecordedInsideACallersTransactionTellsNoListener(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $heard = 0;
        $events = new EventDispatcher();
        $events->addListener(OwnerNoticesChanged::class, static function () use (&$heard): void {
            ++$heard;
        });
        $connection = $this->entities->getConnection();

        $connection->beginTransaction();
        try {
            $this->measuredUseAt('2026-11-02 12:00:00', $events)->record($this->readingOf('main'));
        } finally {
            $connection->rollBack();
        }

        self::assertSame(0, $heard);
        self::assertCount(1, $this->notices()->stillRunningOldestFirst());
    }

    // The recovery commits just before the reader's next statement on the notices, so a read made of two statements would list the outage in both lists.
    public function testANoticeThatEndsBetweenTwoReadsIsWaitingInTheFirstAndEndedInTheSecond(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $recovery = function (): void {
            $this->record('main', '2026-11-02 12:00:00');
        };
        $hook = new class($recovery) extends AbstractLogger {
            private int $noticeReads = 0;

            public function __construct(private ?Closure $recovery)
            {
            }

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $sql = is_string($context['sql'] ?? null) ? $context['sql'] : '';
                if (!$this->recovery instanceof Closure || !str_starts_with($sql, 'SELECT') || !str_contains($sql, 'FROM budget_outage_notice') || 1 === ++$this->noticeReads) {
                    return;
                }

                $recover = $this->recovery;
                $this->recovery = null;
                $recover();
            }
        };
        $connection = DriverManager::getConnection($this->entities->getConnection()->getParams(), new Configuration()->setMiddlewares([new Middleware($hook)]));
        $reader = new DoctrineOwnerNotices(new EntityManager($connection, $this->entities->getConfiguration()));

        $first = $reader->waitingAndEndedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00'));
        $second = $reader->waitingAndEndedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00'));

        self::assertSame([['main'], []], $this->namesOf($first));
        self::assertSame([[], ['main']], $this->namesOf($second));
    }

    public function testTheCombinedReadListsWaitingNoticesOldestFirstAndEndedNoticesNewestFirst(): void
    {
        $this->store('waiting-later', new DateTimeImmutable('2026-11-02T12:00:00+00:00'));
        $this->store('waiting-first', new DateTimeImmutable('2026-11-02T09:00:00+00:00'));
        $this->store('ended-early', new DateTimeImmutable('2026-11-02T09:00:00+00:00'));
        $this->store('ended-late', new DateTimeImmutable('2026-11-02T12:00:00+00:00'));
        $this->record('ended-late', '2026-11-02 15:00:00');
        $this->record('ended-early', '2026-11-02 13:00:00');

        $notices = $this->notices()->waitingAndEndedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00'));

        self::assertSame([['waiting-first', 'waiting-later'], ['ended-late', 'ended-early']], $this->namesOf($notices));
    }

    public function testNoticesEndedAtOneMomentAreListedByTheCombinedReadLastStoredFirst(): void
    {
        $this->store('a', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->store('b', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->record('a', '2026-11-02 12:00:00');
        $this->record('b', '2026-11-02 12:00:00');

        self::assertSame([[], ['b', 'a']], $this->namesOf($this->notices()->waitingAndEndedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00'))));
    }

    public function testTheCombinedReadCarriesEachNoticesMoments(): void
    {
        $this->store('waiting', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->store('ended', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->record('ended', '2026-11-02 12:30:00');

        $notices = $this->notices()->waitingAndEndedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00'));

        self::assertSame('claude_code.waiting 08:00 11:00', $notices->waitingOldestFirst[0]->account->key().' '.$notices->waitingOldestFirst[0]->lastReadingAt->format('H:i').' '.$notices->waitingOldestFirst[0]->noticedAt->format('H:i'));
        self::assertSame('claude_code.ended 2026-11-02 12:30:00', $notices->endedNewestFirst[0]->account->key().' '.$notices->endedNewestFirst[0]->endedAt->format('Y-m-d H:i:s'));
    }

    public function testANoticeEndedOnAnotherUtcDayIsInNeitherListOfTheCombinedRead(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->record('main', '2026-11-02 23:59:59');

        self::assertSame([[], []], $this->namesOf($this->notices()->waitingAndEndedOnTheDayOf(new DateTimeImmutable('2026-11-03T00:00:00+00:00'))));
    }

    // The day is the UTC day of the moment, because every moment Intakt stores is UTC.
    public function testTheCombinedReadTakesAMomentInAnotherZoneAsItsUtcDay(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->record('main', '2026-11-02 23:30:00');

        self::assertSame([[], ['main']], $this->namesOf($this->notices()->waitingAndEndedOnTheDayOf(new DateTimeImmutable('2026-11-03T01:00:00+02:00'))));
    }

    // The saved end alone decides, because a late write or the cleanup of old readings can remove the reading that ended it.
    public function testANoticeWithASavedEndIsOnlyEndedInTheCombinedReadOnceItsEndingReadingIsGone(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->record('main', '2026-11-02 12:00:00');
        $this->entities->getConnection()->executeStatement('DELETE FROM budget_window_reading');

        self::assertSame([[], ['main']], $this->namesOf($this->notices()->waitingAndEndedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00'))));
    }

    // A notice from before ends were kept has no end, and its newer reading still keeps it from waiting.
    public function testANoticeThatEndedBeforeEndsWereKeptIsInNeitherListOfTheCombinedRead(): void
    {
        $this->store('main', new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
        $this->record('main', '2026-11-02 12:00:00');
        $this->entities->getConnection()->executeStatement('UPDATE budget_outage_notice SET ended_at = NULL');

        self::assertSame([[], []], $this->namesOf($this->notices()->waitingAndEndedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00'))));
    }

    public function testNoNoticeGivesTwoEmptyListsInTheCombinedRead(): void
    {
        self::assertSame([[], []], $this->namesOf($this->notices()->waitingAndEndedOnTheDayOf(new DateTimeImmutable('2026-11-02T20:00:00+00:00'))));
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->locks = new LockFactory(new InMemoryStore());
        $this->accountLock = new AccountReadingLock($this->locks);
        $this->entities = $entities;
        EmptyDatabase::rowsIn(self::getContainer());
    }

    /**
     * @return array{list<string>, list<string>}
     */
    private function namesOf(OwnerNoticesOfTheDay $notices): array
    {
        return [
            array_map(static fn (OutageNotice $notice): string => $notice->account->name, $notices->waitingOldestFirst),
            array_map(static fn (EndedOutageNotice $notice): string => $notice->account->name, $notices->endedNewestFirst),
        ];
    }

    private function record(string $account, string $at): void
    {
        self::assertSame(ReadingOutcome::Recorded, $this->measuredUseAt($at)->record($this->readingOf($account)));
    }

    // The reading's first update of the notices is the one in its transaction, so the hook runs while the reading is not yet committed.
    private function afterTheReadingsEndOfOutages(Closure $inside): AbstractLogger
    {
        return new class($inside) extends AbstractLogger {
            public function __construct(private ?Closure $inside)
            {
            }

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                if (!$this->inside instanceof Closure || !str_starts_with(is_string($context['sql'] ?? null) ? $context['sql'] : '', 'UPDATE budget_outage_notice ')) {
                    return;
                }

                $run = $this->inside;
                $this->inside = null;
                $run();
            }
        };
    }

    // The driver logs this just before it commits, so the hook runs at the commit boundary.
    private function atTheDriversCommit(Closure $inside): AbstractLogger
    {
        return new class($inside) extends AbstractLogger {
            public function __construct(private ?Closure $inside)
            {
            }

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                if (!$this->inside instanceof Closure || 'Committing transaction' !== (string) $message) {
                    return;
                }

                $run = $this->inside;
                $this->inside = null;
                $run();
            }
        };
    }

    // Its own connection, because the failure is wired into the driver it connects through.
    private function measuredUseWhoseFirstEndFails(ClockInterface $clock, EventDispatcher $events): DoctrineMeasuredUse
    {
        $connection = DriverManager::getConnection($this->entities->getConnection()->getParams(), new Configuration()->setMiddlewares([new FirstOutageEndFails()]));

        return new DoctrineMeasuredUse(new EntityManager($connection, $this->entities->getConfiguration()), $clock, $events, $this->accountLock);
    }

    private function recordWhileTheEndFails(DoctrineMeasuredUse $worker): void
    {
        try {
            $worker->record($this->readingOf('main'));
            self::fail('The end of the outage was saved.');
        } catch (RuntimeException $failure) {
            self::assertSame(FirstOutageEndFails::FAILURE, $failure->getMessage());
        }
    }

    // Its own lock store, because the tests that run it while a notice writer works stand for another process, which holds a lock this process would wait for.
    private function measuredUseAt(string $at, EventDispatcher $events = new EventDispatcher()): DoctrineMeasuredUse
    {
        return new DoctrineMeasuredUse($this->entities, new MockClock($at.' UTC'), $events, new AccountReadingLock(new LockFactory(new InMemoryStore())));
    }

    private function readingOf(string $account): WindowReading
    {
        return new WindowReading(new Account(Provider::ClaudeCode, $account), UsageWindow::FiveHour, new Usage(12.0, null));
    }

    private function outageNotices(): DoctrineOutageNotices
    {
        return new DoctrineOutageNotices($this->entities, $this->locks, self::createStub(LoggerInterface::class), $this->accountLock);
    }

    /**
     * @return list<string>
     */
    private function namesEndedOnTheDayOf(DateTimeImmutable $moment): array
    {
        return array_map(static fn (EndedOutageNotice $notice): string => $notice->account->name, $this->notices()->endedOnTheDayOf($moment));
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

    private function notices(): DoctrineOwnerNotices
    {
        return new DoctrineOwnerNotices($this->entities);
    }
}
