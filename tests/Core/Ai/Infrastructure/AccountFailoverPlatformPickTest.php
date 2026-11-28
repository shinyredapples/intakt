<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\CoolingAccounts;
use Intakt\Core\Ai\Infrastructure\ModelWaits;
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Budget\Contract\AccountPick;
use Intakt\Core\Budget\Contract\LeftOutAccount;
use Intakt\Core\Budget\Contract\LeftOutCause;
use Intakt\Core\Budget\Contract\RankedAccounts;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Budget\Domain\AccountRoom;
use Intakt\Core\Budget\Domain\CeilingStartGuard;
use Intakt\Core\Budget\Domain\MeasuredRoomRanking;
use Intakt\Tests\Core\Budget\HoldsInMemory;
use Intakt\Tests\Core\Budget\PicksInMemory;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Clock\MockClock;

// Recorded readings drive each start, so the recorded pick is the one measured room gives.
#[CoversClass(AccountFailoverPlatform::class)]
#[CoversClass(CoolingAccounts::class)]
#[CoversClass(MeasuredRoomRanking::class)]
#[UsesClass(AccountPlatform::class)]
#[UsesClass(ModelWaits::class)]
#[UsesClass(SendFailures::class)]
#[UsesClass(CeilingStartGuard::class)]
#[UsesClass(AccountRoom::class)]
#[UsesClass(AccountPick::class)]
#[UsesClass(RankedAccounts::class)]
#[UsesClass(LeftOutAccount::class)]
#[UsesClass(EveryAccountCoolingDown::class)]
#[UsesClass(Account::class)]
#[UsesClass(Usage::class)]
#[UsesClass(UsageWindow::class)]
#[UsesClass(WindowReading::class)]
#[UsesClass(WindowUse::class)]
final class AccountFailoverPlatformPickTest extends TestCase
{
    private MockClock $clock;
    private ReadingsInMemory $readings;
    private PicksInMemory $picks;

    public function testAStartRecordsTheAccountItPickedAndTheLeftOutAccountWithItsCauseAndReset(): void
    {
        $this->weekly(Account::DEFAULT, 95.0, '+2 days');
        $this->weekly('team', 20.0, '+2 days');

        $this->platform(new NoCooldowns())->invoke('big', 'Review.');

        self::assertEquals([new AccountPick(
            Provider::ClaudeCode,
            new Account(Provider::ClaudeCode, 'team'),
            [new LeftOutAccount(new Account(Provider::ClaudeCode, Account::DEFAULT), LeftOutCause::AtWeeklyCeiling, new DateTimeImmutable('2026-11-30T12:00:00+00:00'))],
            new DateTimeImmutable('2026-11-28T12:00:00Z'),
        )], $this->picks->all());
    }

    public function testWithEveryAccountAtACeilingTheStartRecordsNoAccountAndTheEarliestReset(): void
    {
        $this->weekly(Account::DEFAULT, 95.0, '+2 days');
        $this->weekly('team', 95.0, '+3 hours');

        try {
            $this->platform(new NoCooldowns())->invoke('big', 'Review.');
            self::fail('A call started though every account is at a ceiling.');
        } catch (EveryAccountCoolingDown) {
        }

        $pick = $this->picks->latestOf(Provider::ClaudeCode);
        self::assertNotNull($pick);
        self::assertNull($pick->picked);
        self::assertEquals(new DateTimeImmutable('2026-11-28T15:00:00+00:00'), $pick->earliestReset());
    }

    public function testAnAccountDroppedForItsCooldownIsRecordedAsCoolingDownUntilTheCooldownEnds(): void
    {
        $this->platform($this->teamCoolingFor(60))->invoke('big', 'Review.');

        self::assertEquals([new LeftOutAccount(new Account(Provider::ClaudeCode, 'team'), LeftOutCause::CoolingDown, new DateTimeImmutable('2026-11-28T12:01:00+00:00'))], $this->picks->latestOf(Provider::ClaudeCode)?->leftOut);
    }

    public function testACoolingAccountBehindALaterWeeklyCeilingIsRecordedAtTheCeilingWithItsReset(): void
    {
        $this->weekly('team', 95.0, '+3 hours');

        $this->platform($this->teamCoolingFor(60))->invoke('big', 'Review.');

        self::assertEquals([new LeftOutAccount(new Account(Provider::ClaudeCode, 'team'), LeftOutCause::AtWeeklyCeiling, new DateTimeImmutable('2026-11-28T15:00:00+00:00'))], $this->picks->latestOf(Provider::ClaudeCode)?->leftOut);
    }

    public function testACoolingAccountAtACeilingWithNoNamedResetIsRecordedAtTheCeilingWithAnUnknownEnd(): void
    {
        $this->readings->record(new WindowReading(new Account(Provider::ClaudeCode, 'team'), UsageWindow::Weekly, new Usage(95.0, null)));

        $this->platform($this->teamCoolingFor(60))->invoke('big', 'Review.');

        self::assertEquals([new LeftOutAccount(new Account(Provider::ClaudeCode, 'team'), LeftOutCause::AtWeeklyCeiling, null)], $this->picks->latestOf(Provider::ClaudeCode)?->leftOut);
    }

    public function testACooldownThatOutlastsTheCeilingStaysRecordedAsCoolingDown(): void
    {
        $this->weekly('team', 95.0, '+30 seconds');

        $this->platform($this->teamCoolingFor(60))->invoke('big', 'Review.');

        self::assertEquals([new LeftOutAccount(new Account(Provider::ClaudeCode, 'team'), LeftOutCause::CoolingDown, new DateTimeImmutable('2026-11-28T12:01:00+00:00'))], $this->picks->latestOf(Provider::ClaudeCode)?->leftOut);
    }

    public function testATierSelectionReadThatFindsAnAccountFreeRecordsNoPickBecauseTheCallRecordsIt(): void
    {
        $this->weekly(Account::DEFAULT, 95.0, '+2 days');

        $this->platform(new NoCooldowns())->secondsUntilFree('big');

        self::assertSame([], $this->picks->all());
    }

    public function testATierSelectionReadThatFindsNoAccountRecordsTheRefusedStartWithTheEarliestReset(): void
    {
        $this->weekly(Account::DEFAULT, 95.0, '+2 days');
        $this->weekly('team', 95.0, '+3 hours');

        self::assertSame(3 * 3600, $this->platform(new NoCooldowns())->secondsUntilFree('big'));

        self::assertCount(1, $this->picks->all());
        self::assertNull($this->picks->all()[0]->picked);
        self::assertEquals(new DateTimeImmutable('2026-11-28T15:00:00+00:00'), $this->picks->all()[0]->earliestReset());
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock(new DateTimeImmutable('2026-11-28T12:00:00+00:00'));
        $this->readings = new ReadingsInMemory($this->clock);
        $this->picks = new PicksInMemory();
    }

    private function weekly(string $name, float $percent, string $resetsIn): void
    {
        $this->readings->record(new WindowReading(new Account(Provider::ClaudeCode, $name), UsageWindow::Weekly, new Usage($percent, $this->clock->now()->modify($resetsIn))));
    }

    private function teamCoolingFor(int $seconds): AccountCooldowns
    {
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('secondsUntilFree')->willReturnCallback(static fn (Account $account): int => 'team' === $account->name ? $seconds : 0);

        return $cooldowns;
    }

    private function platform(AccountCooldowns $cooldowns): AccountFailoverPlatform
    {
        $ranking = new MeasuredRoomRanking(new HoldsInMemory($this->clock), new CeilingStartGuard($this->readings, $this->clock, ['five-hour' => 90.0, 'weekly' => 90.0]), $this->picks);

        return new AccountFailoverPlatform(Provider::ClaudeCode, [Account::DEFAULT => $this->answering(Account::DEFAULT), 'team' => $this->answering('team')], $cooldowns, $this->clock, new NullLogger(), new NoPinnedProviderCooldowns(), $ranking);
    }

    private function answering(string $name): PlatformInterface
    {
        return MockPlatformFactory::createPlatform($name);
    }
}
