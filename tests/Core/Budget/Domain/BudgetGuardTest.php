<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Domain;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Budget\Domain\BudgetGuard;
use Intakt\Core\Budget\Domain\CeilingStartGuard;
use Intakt\Tests\Core\Budget\HoldsInMemory;
use Intakt\Tests\Core\Budget\NoticesInMemory;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(BudgetGuard::class)]
#[CoversClass(SharedHold::class)]
#[UsesClass(CeilingStartGuard::class)]
#[UsesClass(StartDecision::class)]
#[UsesClass(Account::class)]
#[UsesClass(Usage::class)]
#[UsesClass(UsageWindow::class)]
#[UsesClass(WindowReading::class)]
#[UsesClass(WindowUse::class)]
final class BudgetGuardTest extends TestCase
{
    private MockClock $clock;
    private ReadingsInMemory $readings;
    private HoldsInMemory $holds;
    private NoticesInMemory $notices;
    private BudgetGuard $guard;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-02 08:00:00 UTC');
        $this->readings = new ReadingsInMemory($this->clock);
        $this->holds = new HoldsInMemory($this->clock);
        $this->notices = new NoticesInMemory();
        $ceilings = new CeilingStartGuard($this->readings, $this->clock, ['five-hour' => 90.0, 'weekly' => 90.0]);
        $this->guard = new BudgetGuard($this->holds, $ceilings, $this->readings, $this->notices);
        foreach ([$this->claude('main'), $this->claude('team'), new Account(Provider::Anthropic, 'main')] as $account) {
            $this->readLow($account);
        }
    }

    public function testAUsageLimitCoolsItsAccountDownUntilTheReset(): void
    {
        $this->coolDown($this->claude('main'), '+2 hours');

        self::assertSame(StartDecision::CoolingDown, $this->guard->decide($this->claude('main'), 0));
    }

    public function testACooldownIsNeverAppliedToAnotherAccountOfTheSameOrAnotherProvider(): void
    {
        $this->coolDown($this->claude('main'), '+2 hours');

        self::assertSame(StartDecision::Start, $this->guard->decide($this->claude('team'), 0));
        self::assertSame(StartDecision::Start, $this->guard->decide(new Account(Provider::Anthropic, 'main'), 0));
    }

    public function testACooldownEndsAtItsReset(): void
    {
        $this->coolDown($this->claude('main'), '+2 hours');
        $this->clock->sleep(2 * 3600);
        $this->readLow($this->claude('main'));

        self::assertSame(StartDecision::Start, $this->guard->decide($this->claude('main'), 0));
    }

    public function testACooldownRefusesEvenWhenTheMeasuredUseWouldAdmit(): void
    {
        $this->coolDown($this->claude('main'), '+10 minutes');

        self::assertSame(StartDecision::CoolingDown, $this->guard->decide($this->claude('main'), 0));
        self::assertSame(StartDecision::Start, $this->guard->decide($this->claude('team'), 1));
    }

    public function testASharedBudgetBackOffRefusesStartsOnEveryAccount(): void
    {
        $this->hold(SharedHold::BudgetBackOff, '+30 minutes');

        self::assertSame(StartDecision::BudgetBackedOff, $this->guard->decide($this->claude('main'), 0));
        self::assertSame(StartDecision::BudgetBackedOff, $this->guard->decide(new Account(Provider::Anthropic, 'main'), 0));
    }

    public function testASharedBudgetBackOffStillLetsApprovedHeadsMerge(): void
    {
        $this->hold(SharedHold::BudgetBackOff, '+30 minutes');

        self::assertSame(StartDecision::Start, $this->guard->decideMerge());
    }

    public function testASharedBudgetBackOffEndsAtItsEnd(): void
    {
        $this->hold(SharedHold::BudgetBackOff, '+30 minutes');
        $this->clock->sleep(30 * 60);

        self::assertSame(StartDecision::Start, $this->guard->decide($this->claude('main'), 0));
    }

    public function testAFailureBackOffRefusesStartsOnEveryAccountAndMerges(): void
    {
        $this->hold(SharedHold::FailureBackOff, '+30 minutes');

        self::assertSame(StartDecision::FailureBackedOff, $this->guard->decide($this->claude('team'), 0));
        self::assertSame(StartDecision::FailureBackedOff, $this->guard->decide(new Account(Provider::Anthropic, 'main'), 0));
        self::assertSame(StartDecision::FailureBackedOff, $this->guard->decideMerge());
    }

    public function testAPauseRefusesStartsAndLetsMergesThrough(): void
    {
        $this->hold(SharedHold::Pause, '+1 hour');

        self::assertSame(StartDecision::Paused, $this->guard->decide($this->claude('main'), 0));
        self::assertSame(StartDecision::Start, $this->guard->decideMerge());
    }

    public function testADrainRefusesStartsAndMerges(): void
    {
        $this->hold(SharedHold::Drain, '+15 minutes');

        self::assertSame(StartDecision::Draining, $this->guard->decide($this->claude('main'), 0));
        self::assertSame(StartDecision::Draining, $this->guard->decideMerge());
    }

    public function testAPauseWinsOverABudgetBackOff(): void
    {
        $this->hold(SharedHold::BudgetBackOff, '+1 hour');
        $this->hold(SharedHold::Pause, '+1 hour');

        self::assertSame(StartDecision::Paused, $this->guard->decide($this->claude('main'), 0));
    }

    public function testAFailureBackOffWinsOverAPauseAndHoldsTheMergesThePauseLetsThrough(): void
    {
        $this->hold(SharedHold::Pause, '+1 hour');
        $this->hold(SharedHold::FailureBackOff, '+1 hour');

        self::assertSame(StartDecision::FailureBackedOff, $this->guard->decide($this->claude('main'), 0));
        self::assertSame(StartDecision::FailureBackedOff, $this->guard->decideMerge());
    }

    public function testADrainWinsOverEveryOtherHold(): void
    {
        foreach (SharedHold::cases() as $hold) {
            $this->hold($hold, '+1 hour');
        }

        self::assertSame(StartDecision::Draining, $this->guard->decide($this->claude('main'), 0));
        self::assertSame(StartDecision::Draining, $this->guard->decideMerge());
    }

    public function testASharedHoldWinsOverAnAccountsCooldown(): void
    {
        $this->coolDown($this->claude('main'), '+2 hours');
        $this->hold(SharedHold::BudgetBackOff, '+30 minutes');

        self::assertSame(StartDecision::BudgetBackedOff, $this->guard->decide($this->claude('main'), 0));
    }

    public function testACooldownLetsApprovedHeadsMerge(): void
    {
        $this->coolDown($this->claude('main'), '+2 hours');

        self::assertSame(StartDecision::Start, $this->guard->decideMerge());
    }

    public function testWithoutAHoldTheMeasuredUseDecides(): void
    {
        $this->readings->record(new WindowReading($this->claude('main'), UsageWindow::FiveHour, new Usage(95.0, $this->clock->now()->modify('+1 hour'))));

        self::assertSame(StartDecision::AtCeiling, $this->guard->decide($this->claude('main'), 0));
    }

    public function testAReadingOlderThanThreeHoursIsReportedAsAnOutageSinceThatReading(): void
    {
        $this->clock->sleep(BudgetGuard::OUTAGE_SECONDS);

        $this->guard->decide($this->claude('main'), 0);

        self::assertSame(['claude_code.main since 2026-11-02T08:00:00+00:00'], $this->notices->requested);
    }

    public function testAReadingYoungerThanThreeHoursIsNoOutage(): void
    {
        $this->clock->sleep(BudgetGuard::OUTAGE_SECONDS - 1);

        $this->guard->decide($this->claude('main'), 0);

        self::assertSame([], $this->notices->requested);
    }

    public function testTheNewestReadingOfEitherWindowEndsAnOutage(): void
    {
        $this->clock->sleep(BudgetGuard::OUTAGE_SECONDS);
        $this->readings->record(new WindowReading($this->claude('main'), UsageWindow::Weekly, new Usage(11.0, $this->clock->now()->modify('+5 days'))));

        $this->guard->decide($this->claude('main'), 0);

        self::assertSame([], $this->notices->requested);
    }

    public function testAnAccountNeverReadHasNoOutage(): void
    {
        $this->clock->sleep(BudgetGuard::OUTAGE_SECONDS);

        $this->guard->decide($this->claude('new'), 0);

        self::assertSame([], $this->notices->requested);
    }

    public function testAnOutageIsReportedOnlyForTheAccountAskedAbout(): void
    {
        $this->clock->sleep(BudgetGuard::OUTAGE_SECONDS);
        $this->readLow($this->claude('team'));

        $this->guard->decide($this->claude('team'), 0);

        self::assertSame([], $this->notices->requested);
    }

    private function readLow(Account $account): void
    {
        $this->readings->record(new WindowReading($account, UsageWindow::FiveHour, new Usage(10.0, $this->clock->now()->modify('+4 hours'))));
        $this->readings->record(new WindowReading($account, UsageWindow::Weekly, new Usage(5.0, $this->clock->now()->modify('+6 days'))));
    }

    private function coolDown(Account $account, string $resetIn): void
    {
        $this->holds->coolDown($account, $this->later($resetIn));
    }

    private function hold(SharedHold $hold, string $until): void
    {
        $this->holds->hold($hold, $this->later($until));
    }

    private function later(string $modifier): DateTimeImmutable
    {
        return $this->clock->now()->modify($modifier);
    }

    private function claude(string $name): Account
    {
        return new Account(Provider::ClaudeCode, $name);
    }
}
