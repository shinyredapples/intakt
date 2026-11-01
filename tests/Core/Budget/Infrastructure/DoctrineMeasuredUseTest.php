<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\ReadingOutcome;
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
        $this->record(self::reading(self::claude('main'), UsageWindow::FiveHour, 42.0, '2026-11-01 10:00:00'));

        $this->clock->sleep(90);
        $use = $this->measured->of(self::claude('main'), UsageWindow::FiveHour);

        self::assertNotNull($use);
        self::assertSame(42.0, $use->usedPercent);
        self::assertEquals(new DateTimeImmutable('2026-11-01 10:00:00 UTC'), $use->resetsAt);
        self::assertEquals(new DateTimeImmutable('2026-11-01 08:00:00 UTC'), $use->takenAt);
        self::assertSame(90, $use->ageInSeconds);
    }

    public function testAWindowWithoutAReadingHasNoMeasuredUse(): void
    {
        self::assertNull($this->measured->of(self::claude('main'), UsageWindow::Weekly));
    }

    public function testANewerReadingOfAWindowReplacesTheOlderOne(): void
    {
        $this->record(self::reading(self::claude('main'), UsageWindow::FiveHour, 42.0, '2026-11-01 10:00:00'));
        $this->clock->sleep(600);

        $this->record(self::reading(self::claude('main'), UsageWindow::FiveHour, 55.5, '2026-11-01 10:00:00'));

        $use = $this->measured->of(self::claude('main'), UsageWindow::FiveHour);
        self::assertSame(55.5, $use?->usedPercent);
        self::assertSame(0, $use->ageInSeconds);
    }

    public function testTwoReadingsWithinOneSecondKeepTheOneRecordedLast(): void
    {
        $this->record(self::reading(self::claude('main'), UsageWindow::FiveHour, 42.0, '2026-11-01 10:00:00'));

        $this->record(self::reading(self::claude('main'), UsageWindow::FiveHour, 43.0, '2026-11-01 10:00:00'));

        self::assertSame(43.0, $this->measured->of(self::claude('main'), UsageWindow::FiveHour)?->usedPercent);
    }

    public function testAReadingOfOneWindowLeavesTheOtherWindowOfTheAccountAlone(): void
    {
        $this->record(self::reading(self::claude('main'), UsageWindow::Weekly, 30.0, '2026-11-05 00:00:00'));

        $this->record(self::reading(self::claude('main'), UsageWindow::FiveHour, 80.0, '2026-11-01 10:00:00'));

        self::assertSame(30.0, $this->measured->of(self::claude('main'), UsageWindow::Weekly)?->usedPercent);
    }

    public function testAReadingIsNeverAppliedToAnotherAccountOfTheSameProvider(): void
    {
        $this->record(self::reading(self::claude('main'), UsageWindow::FiveHour, 95.0, '2026-11-01 10:00:00'));

        self::assertNull($this->measured->of(self::claude('second'), UsageWindow::FiveHour));
    }

    public function testAReadingIsNeverAppliedToTheSameAccountNameOfAnotherProvider(): void
    {
        $this->record(self::reading(self::claude('main'), UsageWindow::FiveHour, 95.0, '2026-11-01 10:00:00'));

        self::assertNull($this->measured->of(new Account(Provider::OpenAi, 'main'), UsageWindow::FiveHour));
    }

    public function testAReadingOfAnotherAccountLeavesAnAccountsOwnReadingAsItWas(): void
    {
        $this->record(self::reading(self::claude('main'), UsageWindow::FiveHour, 10.0, '2026-11-01 10:00:00'));

        $this->record(self::reading(self::claude('second'), UsageWindow::FiveHour, 99.0, '2026-11-01 11:00:00'));

        self::assertSame(10.0, $this->measured->of(self::claude('main'), UsageWindow::FiveHour)?->usedPercent);
        self::assertSame(99.0, $this->measured->of(self::claude('second'), UsageWindow::FiveHour)?->usedPercent);
    }

    public function testAReadingWhoseResetHasAlreadyPassedIsRefusedAndTheLastGoodOneStays(): void
    {
        $this->record(self::reading(self::claude('main'), UsageWindow::FiveHour, 20.0, '2026-11-01 10:00:00'));

        $outcome = $this->measured->record(self::reading(self::claude('main'), UsageWindow::FiveHour, 100.0, '2026-11-01 07:59:59'));

        self::assertSame(ReadingOutcome::ResetAlreadyPassed, $outcome);
        self::assertSame(20.0, $this->measured->of(self::claude('main'), UsageWindow::FiveHour)?->usedPercent);
    }

    public function testAReadingThatResetsAtThisVeryMomentIsRefused(): void
    {
        $outcome = $this->measured->record(self::reading(self::claude('main'), UsageWindow::FiveHour, 100.0, '2026-11-01 08:00:00'));

        self::assertSame(ReadingOutcome::ResetAlreadyPassed, $outcome);
        self::assertNull($this->measured->of(self::claude('main'), UsageWindow::FiveHour));
    }

    public function testAReadingWhoseResetIsFurtherAheadThanItsWindowIsLongIsRefused(): void
    {
        $outcome = $this->measured->record(self::reading(self::claude('main'), UsageWindow::FiveHour, 10.0, '2026-11-01 13:00:01'));

        self::assertSame(ReadingOutcome::ResetBeyondWindow, $outcome);
        self::assertNull($this->measured->of(self::claude('main'), UsageWindow::FiveHour));
    }

    public function testAReadingThatResetsExactlyOneWindowLengthAheadIsKept(): void
    {
        $outcome = $this->measured->record(self::reading(self::claude('main'), UsageWindow::Weekly, 10.0, '2026-11-08 08:00:00'));

        self::assertSame(ReadingOutcome::Recorded, $outcome);
        self::assertSame(10.0, $this->measured->of(self::claude('main'), UsageWindow::Weekly)?->usedPercent);
    }

    public function testAReadingWithoutAResetTimeIsKeptWithoutOne(): void
    {
        $outcome = $this->measured->record(new WindowReading(self::claude('main'), UsageWindow::Weekly, 12.0, null));

        self::assertSame(ReadingOutcome::Recorded, $outcome);
        self::assertNull($this->measured->of(self::claude('main'), UsageWindow::Weekly)?->resetsAt);
    }

    public function testOnlyTheLatestReadingOfAWindowStaysStored(): void
    {
        foreach ([10.0, 20.0, 30.0] as $percent) {
            $this->record(self::reading(self::claude('main'), UsageWindow::FiveHour, $percent, '2026-11-01 10:00:00'));
        }
        $this->record(self::reading(self::claude('second'), UsageWindow::FiveHour, 5.0, '2026-11-01 10:00:00'));

        self::assertSame(2, $this->entities->getRepository(WindowReadingRecord::class)->count());
    }

    public function testAReadingIsVisibleToAnotherWorker(): void
    {
        $this->record(self::reading(self::claude('main'), UsageWindow::FiveHour, 61.0, '2026-11-01 10:00:00'));
        $this->entities->clear();

        self::assertSame(61.0, $this->measured->of(self::claude('main'), UsageWindow::FiveHour)?->usedPercent);
    }

    private function record(WindowReading $reading): void
    {
        self::assertSame(ReadingOutcome::Recorded, $this->measured->record($reading));
    }

    private static function claude(string $name): Account
    {
        return new Account(Provider::ClaudeCode, $name);
    }

    private static function reading(Account $account, UsageWindow $window, float $usedPercent, string $resetsAt): WindowReading
    {
        return new WindowReading($account, $window, $usedPercent, new DateTimeImmutable($resetsAt.' UTC'));
    }
}
