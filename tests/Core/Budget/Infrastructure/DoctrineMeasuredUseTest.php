<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\ReadingOutcome;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Budget\Domain\WindowReadingRecord;
use Intakt\Core\Budget\Infrastructure\DoctrineMeasuredUse;
use Intakt\Tests\Application\EmptyDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

#[Group('persistence')]
#[CoversClass(DoctrineMeasuredUse::class)]
#[CoversClass(WindowReadingRecord::class)]
#[CoversClass(WindowUse::class)]
#[CoversClass(UsageWindow::class)]
#[UsesClass(WindowReading::class)]
#[UsesClass(Usage::class)]
#[UsesClass(Account::class)]
final class DoctrineMeasuredUseTest extends KernelTestCase
{
    private EntityManagerInterface $entities;
    private MockClock $clock;
    private DoctrineMeasuredUse $measured;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->clock = new MockClock('2026-11-01 08:00:00 UTC');
        $this->measured = new DoctrineMeasuredUse($entities, $this->clock);
    }

    public function testAReadingIsKeptWithItsResetTimeAndItsAgeOnTheClock(): void
    {
        $this->record($this->reading($this->claude('main'), UsageWindow::FiveHour, 42.0));

        $this->clock->sleep(90);
        $use = $this->measured->of($this->claude('main'), UsageWindow::FiveHour);

        self::assertNotNull($use);
        self::assertSame(42.0, $use->usage->usedPercent);
        self::assertEquals(new DateTimeImmutable('2026-11-01T10:00:00Z'), $use->usage->resetsAt);
        self::assertEquals(new DateTimeImmutable('2026-11-01T08:00:00Z'), $use->takenAt);
        self::assertSame(90, $use->ageInSeconds);
    }

    public function testAWindowWithoutAReadingHasNoMeasuredUse(): void
    {
        self::assertNull($this->measured->of($this->claude('main'), UsageWindow::Weekly));
    }

    public function testANewerReadingOfAWindowReplacesTheOlderOne(): void
    {
        $this->record($this->reading($this->claude('main'), UsageWindow::FiveHour, 42.0));
        $this->clock->sleep(600);

        $this->record($this->reading($this->claude('main'), UsageWindow::FiveHour, 55.5));

        $use = $this->measured->of($this->claude('main'), UsageWindow::FiveHour);
        self::assertSame(55.5, $use?->usage->usedPercent);
        self::assertSame(0, $use->ageInSeconds);
    }

    public function testTwoReadingsWithinOneSecondKeepTheOneRecordedLast(): void
    {
        $this->record($this->reading($this->claude('main'), UsageWindow::FiveHour, 42.0));

        $this->record($this->reading($this->claude('main'), UsageWindow::FiveHour, 43.0));

        self::assertSame(43.0, $this->measured->of($this->claude('main'), UsageWindow::FiveHour)?->usage->usedPercent);
    }

    public function testAReadingOfOneWindowLeavesTheOtherWindowOfTheAccountAlone(): void
    {
        $this->record($this->reading($this->claude('main'), UsageWindow::Weekly, 30.0));

        $this->record($this->reading($this->claude('main'), UsageWindow::FiveHour, 80.0));

        self::assertSame(30.0, $this->measured->of($this->claude('main'), UsageWindow::Weekly)?->usage->usedPercent);
    }

    public function testAReadingIsNeverAppliedToAnotherAccountOfTheSameProvider(): void
    {
        $this->record($this->reading($this->claude('main'), UsageWindow::FiveHour, 95.0));

        self::assertNull($this->measured->of($this->claude('second'), UsageWindow::FiveHour));
    }

    public function testAReadingIsNeverAppliedToTheSameAccountNameOfAnotherProvider(): void
    {
        $this->record($this->reading($this->claude('main'), UsageWindow::FiveHour, 95.0));

        self::assertNull($this->measured->of(new Account(Provider::OpenAi, 'main'), UsageWindow::FiveHour));
    }

    public function testAReadingOfAnotherAccountLeavesAnAccountsOwnReadingAsItWas(): void
    {
        $this->record($this->reading($this->claude('main'), UsageWindow::FiveHour, 10.0));

        $this->record($this->reading($this->claude('second'), UsageWindow::FiveHour, 99.0));

        self::assertSame(10.0, $this->measured->of($this->claude('main'), UsageWindow::FiveHour)?->usage->usedPercent);
        self::assertSame(99.0, $this->measured->of($this->claude('second'), UsageWindow::FiveHour)?->usage->usedPercent);
    }

    public function testAReadingWhoseResetHasAlreadyPassedIsRefusedAndTheLastGoodOneStays(): void
    {
        $this->record($this->reading($this->claude('main'), UsageWindow::FiveHour, 20.0));

        $outcome = $this->measured->record(new WindowReading($this->claude('main'), UsageWindow::FiveHour, new Usage(100.0, new DateTimeImmutable('2026-11-01T07:59:59Z'))));

        self::assertSame(ReadingOutcome::ResetAlreadyPassed, $outcome);
        self::assertSame(20.0, $this->measured->of($this->claude('main'), UsageWindow::FiveHour)?->usage->usedPercent);
    }

    public function testAReadingThatResetsAtThisVeryMomentIsRefused(): void
    {
        $outcome = $this->measured->record(new WindowReading($this->claude('main'), UsageWindow::FiveHour, new Usage(100.0, new DateTimeImmutable('2026-11-01T08:00:00Z'))));

        self::assertSame(ReadingOutcome::ResetAlreadyPassed, $outcome);
        self::assertNull($this->measured->of($this->claude('main'), UsageWindow::FiveHour));
    }

    public function testAReadingWhoseResetIsFurtherAheadThanItsWindowIsLongIsRefused(): void
    {
        $outcome = $this->measured->record(new WindowReading($this->claude('main'), UsageWindow::FiveHour, new Usage(10.0, new DateTimeImmutable('2026-11-01T13:00:01Z'))));

        self::assertSame(ReadingOutcome::ResetBeyondWindow, $outcome);
        self::assertNull($this->measured->of($this->claude('main'), UsageWindow::FiveHour));
    }

    public function testAReadingThatResetsExactlyOneWindowLengthAheadIsKept(): void
    {
        $outcome = $this->measured->record(new WindowReading($this->claude('main'), UsageWindow::Weekly, new Usage(10.0, new DateTimeImmutable('2026-11-08T08:00:00Z'))));

        self::assertSame(ReadingOutcome::Recorded, $outcome);
        self::assertSame(10.0, $this->measured->of($this->claude('main'), UsageWindow::Weekly)?->usage->usedPercent);
    }

    public function testAReadingWithoutAResetTimeIsKeptWithoutOne(): void
    {
        $outcome = $this->measured->record(new WindowReading($this->claude('main'), UsageWindow::Weekly, new Usage(12.0, null)));

        self::assertSame(ReadingOutcome::Recorded, $outcome);
        self::assertNull($this->measured->of($this->claude('main'), UsageWindow::Weekly)?->usage->resetsAt);
    }

    public function testOnlyTheLatestReadingOfAWindowStaysStored(): void
    {
        foreach ([10.0, 20.0, 30.0] as $percent) {
            $this->record($this->reading($this->claude('main'), UsageWindow::FiveHour, $percent));
        }
        $this->record($this->reading($this->claude('second'), UsageWindow::FiveHour, 5.0));

        self::assertSame(2, $this->entities->getRepository(WindowReadingRecord::class)->count());
    }

    public function testAReadingIsVisibleToAnotherWorker(): void
    {
        $this->record($this->reading($this->claude('main'), UsageWindow::FiveHour, 61.0));
        $this->entities->clear();

        self::assertSame(61.0, $this->measured->of($this->claude('main'), UsageWindow::FiveHour)?->usage->usedPercent);
    }

    public function testAReadingAnotherWorkerRecordsBetweenAnInsertAndItsCleanupIsTheOneThatStays(): void
    {
        $other = $this->worker(new EventManager());
        $events = new EventManager();
        $events->addEventListener(Events::postFlush, new OnceFlushed(fn () => self::assertSame(ReadingOutcome::Recorded, $other->record($this->reading($this->claude('main'), UsageWindow::FiveHour, 43.0)))));
        $first = $this->worker($events);

        self::assertSame(ReadingOutcome::Recorded, $first->record($this->reading($this->claude('main'), UsageWindow::FiveHour, 42.0)));

        self::assertSame(43.0, $this->measured->of($this->claude('main'), UsageWindow::FiveHour)?->usage->usedPercent);
        self::assertSame(1, $this->entities->getRepository(WindowReadingRecord::class)->count());
    }

    private function record(WindowReading $reading): void
    {
        self::assertSame(ReadingOutcome::Recorded, $this->measured->record($reading));
    }

    // Another worker: its own connection and entity manager, as another messenger:consume process has.
    private function worker(EventManager $events): DoctrineMeasuredUse
    {
        $connection = DriverManager::getConnection($this->entities->getConnection()->getParams());

        return new DoctrineMeasuredUse(new EntityManager($connection, $this->entities->getConfiguration(), $events), $this->clock);
    }

    private function claude(string $name): Account
    {
        return new Account(Provider::ClaudeCode, $name);
    }

    // Each window resets inside its length from the clock's start, so a reading built here is always kept.
    private function reading(Account $account, UsageWindow $window, float $usedPercent): WindowReading
    {
        $resetsAt = match ($window) {
            UsageWindow::FiveHour => new DateTimeImmutable('2026-11-01T10:00:00Z'),
            UsageWindow::Weekly => new DateTimeImmutable('2026-11-05T00:00:00Z'),
        };

        return new WindowReading($account, $window, new Usage($usedPercent, $resetsAt));
    }
}
