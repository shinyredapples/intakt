<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Infrastructure;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\ReadingOutcome;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Domain\AccountCooldownRecord;
use Intakt\Core\Budget\Domain\SharedHoldRecord;
use Intakt\Core\Budget\Infrastructure\DoctrineHolds;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;

#[Group('persistence')]
#[CoversClass(DoctrineHolds::class)]
#[CoversClass(AccountCooldownRecord::class)]
#[CoversClass(SharedHoldRecord::class)]
#[UsesClass(UsageWindow::class)]
#[UsesClass(SharedHold::class)]
#[UsesClass(Account::class)]
final class DoctrineHoldsTest extends KernelTestCase
{
    private MockClock $clock;
    private DoctrineHolds $holds;

    public function testAUsageLimitCoolsTheAccountDownUntilItsReset(): void
    {
        self::assertSame(ReadingOutcome::Recorded, $this->holds->coolDown($this->claude('main'), $this->later('+2 hours')));

        $this->clock->sleep(2 * 3600 - 1);
        self::assertTrue($this->holds->isCoolingDown($this->claude('main')));
        $this->clock->sleep(1);
        self::assertFalse($this->holds->isCoolingDown($this->claude('main')));
    }

    public function testACooldownHoldsOnlyItsOwnAccount(): void
    {
        $this->cool($this->claude('main'), '+2 hours');

        self::assertFalse($this->holds->isCoolingDown($this->claude('team')));
        self::assertFalse($this->holds->isCoolingDown(new Account(Provider::Anthropic, 'main')));
    }

    public function testAWeeklyLimitCoolsDownForDays(): void
    {
        $this->cool($this->claude('main'), '+6 days');
        $this->clock->sleep(5 * 86400);

        self::assertTrue($this->holds->isCoolingDown($this->claude('main')));
    }

    public function testAnEarlierResetNeverShortensARunningCooldown(): void
    {
        $this->cool($this->claude('main'), '+5 hours');
        $this->cool($this->claude('main'), '+1 hour');
        $this->clock->sleep(2 * 3600);

        self::assertTrue($this->holds->isCoolingDown($this->claude('main')));
    }

    public function testAResetAlreadyPassedCoolsNothingDown(): void
    {
        self::assertSame(ReadingOutcome::ResetAlreadyPassed, $this->holds->coolDown($this->claude('main'), $this->clock->now()));
        self::assertFalse($this->holds->isCoolingDown($this->claude('main')));
    }

    public function testAResetBeyondTheLongestWindowCoolsNothingDown(): void
    {
        $beyond = $this->later('+'.(UsageWindow::longest()->seconds() + 1).' seconds');

        self::assertSame(ReadingOutcome::ResetBeyondWindow, $this->holds->coolDown($this->claude('main'), $beyond));
        self::assertFalse($this->holds->isCoolingDown($this->claude('main')));
    }

    public function testAResetAtTheEndOfTheLongestWindowIsKept(): void
    {
        $atTheEnd = $this->later('+'.UsageWindow::longest()->seconds().' seconds');

        self::assertSame(ReadingOutcome::Recorded, $this->holds->coolDown($this->claude('main'), $atTheEnd));
    }

    public function testNoSharedHoldIsInEffectWithoutOne(): void
    {
        self::assertSame([], $this->holds->sharedInEffect());
    }

    public function testSharedHoldsInEffectComeInTheOrderTheyWin(): void
    {
        $this->holds->hold(SharedHold::BudgetBackOff, $this->later('+1 hour'));
        $this->holds->hold(SharedHold::Drain, $this->later('+1 hour'));
        $this->holds->hold(SharedHold::BudgetBackOff, $this->later('+2 hours'));

        self::assertSame([SharedHold::Drain, SharedHold::BudgetBackOff], $this->holds->sharedInEffect());
    }

    public function testASharedHoldEndsAtItsEnd(): void
    {
        $this->holds->hold(SharedHold::Pause, $this->later('+1 hour'));
        $this->clock->sleep(3600);

        self::assertSame([], $this->holds->sharedInEffect());
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::rowsIn(self::getContainer());
        $this->clock = new MockClock('2026-11-02 08:00:00 UTC');
        $this->holds = new DoctrineHolds($entities, $this->clock);
    }

    private function cool(Account $account, string $resetIn): void
    {
        self::assertSame(ReadingOutcome::Recorded, $this->holds->coolDown($account, $this->later($resetIn)));
    }

    private function claude(string $name): Account
    {
        return new Account(Provider::ClaudeCode, $name);
    }

    private function later(string $modifier): DateTimeImmutable
    {
        return $this->clock->now()->modify($modifier);
    }
}
