<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Domain;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Budget\Domain\AccountRoom;
use Intakt\Core\Budget\Domain\CeilingStartGuard;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(CeilingStartGuard::class)]
#[CoversClass(StartDecision::class)]
#[UsesClass(Account::class)]
#[UsesClass(AccountRoom::class)]
#[UsesClass(Usage::class)]
#[UsesClass(UsageWindow::class)]
#[UsesClass(WindowReading::class)]
#[UsesClass(WindowUse::class)]
final class CeilingStartGuardTest extends TestCase
{
    private MockClock $clock;
    private ReadingsInMemory $readings;
    // Six days ahead puts the start one day into the week, so the weekly pace is 90 * (24 + 5) / 168 percent, about 15.5.
    private DateTimeImmutable $weekResets;

    public function testAStartIsAdmittedBelowTheFiveHourCeiling(): void
    {
        $this->read(89.9, 10.0);

        self::assertSame(StartDecision::Start, $this->decide(0));
    }

    public function testAStartIsRefusedAtTheDefaultFiveHourCeilingOfNinetyPercent(): void
    {
        $this->read(90.0, 10.0);

        self::assertSame(StartDecision::AtCeiling, $this->decide(0));
    }

    public function testAStartIsRefusedAtTheWeeklyCeiling(): void
    {
        $this->clock->modify('+5 days');
        $this->read(10.0, 90.0);

        self::assertSame(StartDecision::AtCeiling, $this->decide(0));
    }

    public function testTheOwnersCeilingReplacesTheDefault(): void
    {
        $this->read(75.0, 10.0);

        self::assertSame(StartDecision::AtCeiling, $this->decide(0, ['five-hour' => 70.0, 'weekly' => 90.0]));
        self::assertSame(StartDecision::Start, $this->decide(0, ['five-hour' => 80.0, 'weekly' => 90.0]));
    }

    public function testOnTheWeeklyPaceTheFiveHourWindowRunsToItsCeiling(): void
    {
        $this->read(89.0, 15.5);

        self::assertSame(StartDecision::Start, $this->decide(0));
    }

    public function testAheadOfTheWeeklyPaceTheFiveHourCeilingDropsByTheShareThePaceAllows(): void
    {
        $this->read(46.0, 31.0);

        self::assertSame(StartDecision::AtCeiling, $this->decide(0));
    }

    public function testAheadOfTheWeeklyPaceAFiveHourUseBelowTheDroppedCeilingStillStarts(): void
    {
        $this->read(44.0, 31.0);

        self::assertSame(StartDecision::Start, $this->decide(0));
    }

    public function testAStaleReadingAtTheCeilingStillRefusesUntilTheWindowResets(): void
    {
        $this->read(95.0, 10.0);
        $this->clock->sleep(2 * 3600);

        self::assertSame(StartDecision::AtCeiling, $this->decide(0));
    }

    public function testAReadingWhoseResetHasPassedNoLongerRefuses(): void
    {
        $this->read(95.0, 10.0);
        $this->clock->sleep(3 * 3600);

        self::assertSame(StartDecision::Start, $this->decide(0));
    }

    public function testAReadingWithoutResetTimeAtTheCeilingRefusesWithinItsWindowLength(): void
    {
        $this->readWindow(UsageWindow::FiveHour, 95.0, null);
        $this->clock->sleep(UsageWindow::FiveHour->seconds() - 1);

        self::assertSame(StartDecision::AtCeiling, $this->decide(0));
    }

    public function testAReadingWithoutResetTimeOlderThanItsWindowLengthNoLongerRefuses(): void
    {
        $this->readWindow(UsageWindow::FiveHour, 95.0, null);
        $this->clock->sleep(2 * 86400);

        self::assertSame(StartDecision::Start, $this->decide(0));
    }

    public function testWithoutAReadingOneCallStarts(): void
    {
        self::assertSame(StartDecision::Start, $this->decide(0));
    }

    public function testWithoutAReadingASecondParallelStartIsRefused(): void
    {
        self::assertSame(StartDecision::NoTrustedReading, $this->decide(1));
    }

    public function testWithOnlyOneWindowReadASecondParallelStartIsRefused(): void
    {
        $this->readWindow(UsageWindow::FiveHour, 10.0, $this->fiveHourReset());

        self::assertSame(StartDecision::NoTrustedReading, $this->decide(1));
    }

    public function testAReadingOlderThanItsTrustAllowsOnlyOneCallAtATime(): void
    {
        $this->read(10.0, 10.0);
        $this->clock->sleep(CeilingStartGuard::TRUSTED_FOR_SECONDS + 1);

        self::assertSame(StartDecision::Start, $this->decide(0));
        self::assertSame(StartDecision::NoTrustedReading, $this->decide(1));
    }

    public function testASecondParallelStartNeedsAFresherReadingThanTheFirst(): void
    {
        $this->read(10.0, 10.0);
        $this->clock->sleep(CeilingStartGuard::PARALLEL_TRUSTED_FOR_SECONDS + 1);

        self::assertSame(StartDecision::ReadingNotFreshEnough, $this->decide(1));
    }

    public function testASecondParallelStartNeedsAMarginBelowTheCeiling(): void
    {
        $this->read(80.0, 10.0);

        self::assertSame(StartDecision::Start, $this->decide(0));
        self::assertSame(StartDecision::NoMarginForParallelStart, $this->decide(1));
    }

    public function testASecondParallelStartWithAFreshReadingAndAMarginStarts(): void
    {
        $this->read(79.0, 10.0);
        $this->clock->sleep(CeilingStartGuard::PARALLEL_TRUSTED_FOR_SECONDS);

        self::assertSame(StartDecision::Start, $this->decide(2));
    }

    public function testAReadingOfAnotherAccountIsNeverApplied(): void
    {
        $this->readFor(new Account(Provider::ClaudeCode, 'team'), 95.0);
        $this->readFor(new Account(Provider::Anthropic, 'main'), 95.0);
        $this->read(10.0, 10.0);

        self::assertSame(StartDecision::Start, $this->decide(1));
        self::assertSame(StartDecision::AtCeiling, $this->guard()->decide(new Account(Provider::ClaudeCode, 'team'), 0));
    }

    public function testMeasuredUseAloneDecidesSoAWindowMeasuredAtFivePercentAdmitsEveryStart(): void
    {
        $this->read(5.0, 5.0);

        self::assertSame(StartDecision::Start, $this->decide(0));
        self::assertSame(StartDecision::Start, $this->decide(1));
    }

    // The pace needs 50 * 40 / (90 * 90) of the week elapsed, counting the current five hours: 149333.3 s against 104400 s now.
    public function testAShortWindowWithoutResetTimeHeldOnlyByTheWeeklyPaceIsFreeOnceThePaceCatchesUp(): void
    {
        $this->readWindow(UsageWindow::Weekly, 40.0, $this->weekResets);
        $this->readWindow(UsageWindow::FiveHour, 50.0, null);

        $room = $this->guard()->roomOf($this->account());

        self::assertSame([UsageWindow::FiveHour, 44934], [$room->atCeiling, $room->resetsAt?->getTimestamp() - $this->clock->now()->getTimestamp()]);
    }

    // At 81% of the week with 47 h 10 min left, the pace lifts the short ceiling above 75% after 600 s, before the short window resets after 1200 s.
    public function testARisingWeeklyPaceFreesAShortWindowBeforeEitherReset(): void
    {
        $this->readWindow(UsageWindow::Weekly, 81.0, $this->clock->now()->modify('+47 hours 10 minutes'));
        $this->readWindow(UsageWindow::FiveHour, 75.0, $this->clock->now()->modify('+20 minutes'));
        $room = $this->guard()->roomOf($this->account());
        $wait = $room->resetsAt?->getTimestamp() - $this->clock->now()->getTimestamp();

        $this->clock->sleep(600);
        $atTheBoundary = $this->guard()->roomOf($this->account())->atCeiling;
        $this->clock->sleep(1);

        self::assertSame([UsageWindow::FiveHour, 601, UsageWindow::FiveHour, null], [$room->atCeiling, $wait, $atTheBoundary, $this->guard()->roomOf($this->account())->atCeiling]);
    }

    public function testAShortWindowWithoutResetTimeAtItsOwnCeilingIsFreeAtAnUnknownTime(): void
    {
        $this->readWindow(UsageWindow::Weekly, 40.0, $this->weekResets);
        $this->readWindow(UsageWindow::FiveHour, 95.0, null);

        $room = $this->guard()->roomOf($this->account());

        self::assertSame([UsageWindow::FiveHour, null], [$room->atCeiling, $room->resetsAt]);
    }

    public function testOnlyAStartDecisionAdmits(): void
    {
        $admitting = array_filter(StartDecision::cases(), static fn (StartDecision $decision): bool => $decision->admits());

        self::assertSame([StartDecision::Start], array_values($admitting));
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-02 08:00:00 UTC');
        $this->readings = new ReadingsInMemory($this->clock);
        $this->weekResets = $this->clock->now()->modify('+6 days');
    }

    private function read(float $fiveHour, float $weekly): void
    {
        $this->readWindow(UsageWindow::FiveHour, $fiveHour, $this->fiveHourReset());
        $this->readWindow(UsageWindow::Weekly, $weekly, $this->weekResets);
    }

    /**
     * @param array<string, float> $ceilings
     */
    private function decide(int $callsRunning, array $ceilings = ['five-hour' => 90.0, 'weekly' => 90.0]): StartDecision
    {
        return $this->guard($ceilings)->decide($this->account(), $callsRunning);
    }

    private function readFor(Account $account, float $percent): void
    {
        foreach (UsageWindow::cases() as $window) {
            $this->readings->record(new WindowReading($account, $window, new Usage($percent, $this->fiveHourReset())));
        }
    }

    /**
     * @param array<string, float> $ceilings
     */
    private function guard(array $ceilings = ['five-hour' => 90.0, 'weekly' => 90.0]): CeilingStartGuard
    {
        return new CeilingStartGuard($this->readings, $this->clock, $ceilings);
    }

    private function readWindow(UsageWindow $window, float $percent, ?DateTimeImmutable $resetsAt): void
    {
        $this->readings->record(new WindowReading($this->account(), $window, new Usage($percent, $resetsAt)));
    }

    private function fiveHourReset(): DateTimeImmutable
    {
        return $this->clock->now()->modify('+3 hours');
    }

    private function account(): Account
    {
        return new Account(Provider::ClaudeCode, 'main');
    }
}
