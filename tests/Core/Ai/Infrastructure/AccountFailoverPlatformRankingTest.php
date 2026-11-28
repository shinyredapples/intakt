<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\ModelWaits;
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Budget\Contract\LeftOutAccount;
use Intakt\Core\Budget\Contract\RankedAccounts;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Budget\Domain\AccountRoom;
use Intakt\Core\Budget\Domain\CeilingStartGuard;
use Intakt\Core\Budget\Domain\MeasuredRoomRanking;
use Intakt\Tests\Core\Budget\HoldsInMemory;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Clock\MockClock;

#[CoversClass(AccountFailoverPlatform::class)]
#[UsesClass(AccountPlatform::class)]
#[UsesClass(ModelWaits::class)]
#[UsesClass(SendFailures::class)]
#[UsesClass(MeasuredRoomRanking::class)]
#[UsesClass(CeilingStartGuard::class)]
#[UsesClass(AccountRoom::class)]
#[UsesClass(RankedAccounts::class)]
#[UsesClass(LeftOutAccount::class)]
#[UsesClass(EveryAccountCoolingDown::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Usage::class)]
#[UsesClass(UsageWindow::class)]
#[UsesClass(WindowReading::class)]
#[UsesClass(WindowUse::class)]
final class AccountFailoverPlatformRankingTest extends TestCase
{
    private MockClock $clock;
    private ReadingsInMemory $readings;
    private HoldsInMemory $holds;
    /** @var list<string> */
    private array $called = [];

    public function testAReviewWhoseFirstAccountIsAtItsWeeklyCeilingStartsOnTheSecondWithNoRefusedCallFirst(): void
    {
        $this->both(Account::DEFAULT, $this->usage(10.0, 5), $this->usage(95.0, 60));
        $this->both('team', $this->usage(10.0, 5), $this->usage(20.0, 60));

        $result = $this->platform()->invoke('big', 'Review.');

        self::assertSame('team', $result->asText());
        self::assertSame(['team'], $this->called);
    }

    public function testWithRoomOnBothTheAccountWhoseWindowResetsSoonerTakesTheCall(): void
    {
        $this->both(Account::DEFAULT, $this->usage(10.0, 4), $this->usage(20.0, 60));
        $this->both('team', $this->usage(10.0, 1), $this->usage(20.0, 60));

        self::assertSame('team', $this->platform()->invoke('big', 'Review.')->asText());
        self::assertSame(['team'], $this->called);
    }

    public function testAnAccountThatCrossesItsShortWindowCeilingBetweenTwoCallsLosesTheNextCall(): void
    {
        $this->both(Account::DEFAULT, $this->usage(10.0, 1), $this->usage(20.0, 60));
        $this->both('team', $this->usage(10.0, 4), $this->usage(20.0, 60));
        $platform = $this->platform();

        self::assertSame(Account::DEFAULT, $platform->invoke('big', 'Review.')->asText());
        $this->both(Account::DEFAULT, $this->usage(92.0, 1), $this->usage(20.0, 60));

        self::assertSame('team', $platform->invoke('big', 'Review.')->asText());
        self::assertSame([Account::DEFAULT, 'team'], $this->called);
    }

    public function testWithEveryAccountAtACeilingNoCallIsSentAndTheWaitIsTheEarliestReset(): void
    {
        $this->both(Account::DEFAULT, $this->usage(95.0, 3), $this->usage(20.0, 60));
        $this->both('team', $this->usage(10.0, 5), $this->usage(95.0, 40));

        try {
            $this->platform()->invoke('big', 'Review.');
            self::fail('A call was sent though every account is at a ceiling.');
        } catch (EveryAccountCoolingDown $out) {
            self::assertSame(3 * 3600, $out->secondsUntilAnAccountIsFree);
        }

        self::assertSame([], $this->called);
    }

    public function testWithOneAccountCoolingDownAndTheOtherAtItsCeilingTheWaitIsTheCooldownThatEndsSooner(): void
    {
        $this->both(Account::DEFAULT, $this->usage(10.0, 5), $this->usage(95.0, 2));

        self::assertSame(60, $this->waitOf($this->platform($this->teamCoolingFor(60))));
        self::assertSame([], $this->called);
    }

    public function testAnAccountBothCoolingDownAndAtAFreshCeilingWaitsUntilBothHaveEnded(): void
    {
        $account = new Account(Provider::ClaudeCode, Account::DEFAULT);
        $this->readings->record(new WindowReading($account, UsageWindow::Weekly, new Usage(95.0, $this->clock->now()->modify('+5 minutes'))));
        $wait = 60;
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('secondsUntilFree')->willReturnCallback(static function () use (&$wait): int {
            return $wait;
        });
        $platform = new AccountFailoverPlatform(Provider::ClaudeCode, [Account::DEFAULT => $this->answering(Account::DEFAULT)], $cooldowns, $this->clock, new NullLogger(), new NoPinnedProviderCooldowns(), $this->ranking());

        $cooldownAndCeiling = $platform->secondsUntilFree('big');
        $wait = 0;
        $this->clock->sleep(60);
        $afterTheCooldown = $platform->secondsUntilFree('big');
        $this->holds->coolDown($account, $this->clock->now()->modify('+1 minute'));
        $holdAndCeiling = $platform->secondsUntilFree('big');
        $this->clock->sleep(60);
        $afterTheHold = $platform->secondsUntilFree('big');

        self::assertSame([300, 240, 240, 180], [$cooldownAndCeiling, $afterTheCooldown, $holdAndCeiling, $afterTheHold]);
    }

    public function testAnAccountAtBothCeilingsOnFreshReadingsIsFreeAtTheLaterReset(): void
    {
        $account = new Account(Provider::ClaudeCode, Account::DEFAULT);
        $this->readings->record(new WindowReading($account, UsageWindow::Weekly, new Usage(95.0, $this->clock->now()->modify('+300 seconds'))));
        $this->readings->record(new WindowReading($account, UsageWindow::FiveHour, new Usage(95.0, $this->clock->now()->modify('+600 seconds'))));
        $platform = new AccountFailoverPlatform(Provider::ClaudeCode, [Account::DEFAULT => $this->answering(Account::DEFAULT)], new NoCooldowns(), $this->clock, new NullLogger(), new NoPinnedProviderCooldowns(), $this->ranking());

        $bothAtCeiling = $platform->secondsUntilFree('big');
        $this->clock->sleep(300);
        $afterTheWeeklyReset = $platform->secondsUntilFree('big');

        self::assertSame([600, 300], [$bothAtCeiling, $afterTheWeeklyReset]);
    }

    // Both readings stay below their ceiling of 90, so only the pace holds; 81 * 80 / 8100 of the 604800 s week is 483840 s, which the 465240 s elapsed plus the 18000 s short window reach after 600 s.
    public function testAShortWindowHeldOnlyByTheWeeklyPaceIsFreeOnceThePaceLiftsItsCeiling(): void
    {
        $account = new Account(Provider::ClaudeCode, Account::DEFAULT);
        $ranking = $this->ranking();
        $platform = new AccountFailoverPlatform(Provider::ClaudeCode, [Account::DEFAULT => $this->answering(Account::DEFAULT)], new NoCooldowns(), $this->clock, new NullLogger(), new NoPinnedProviderCooldowns(), $ranking);
        $this->readings->record(new WindowReading($account, UsageWindow::Weekly, new Usage(80.0, $this->clock->now()->modify('+139560 seconds'))));
        $this->readings->record(new WindowReading($account, UsageWindow::FiveHour, new Usage(81.0, $this->clock->now()->modify('+3 hours'))));

        $reported = $platform->secondsUntilFree('big');
        $this->clock->sleep(600);
        $atTheBoundary = $platform->secondsUntilFree('big');
        $this->clock->sleep(1);
        $afterThePaceRelease = $platform->secondsUntilFree('big');
        $rankedAfter = count($ranking->rank([$account])->inOrder);

        self::assertSame([601, 1, 0, 1], [$reported, $atTheBoundary, $afterThePaceRelease, $rankedAfter]);
    }

    public function testAShortWindowHeldByAWeekAheadOfItsPaceIsFreeAtItsOwnSoonerReset(): void
    {
        $this->both(Account::DEFAULT, $this->usage(75.0, 2), $this->usage(80.0, 60));
        $platform = new AccountFailoverPlatform(Provider::ClaudeCode, [Account::DEFAULT => $this->answering(Account::DEFAULT)], new NoCooldowns(), $this->clock, new NullLogger(), new NoPinnedProviderCooldowns(), $this->ranking());

        $heldByThePace = $platform->secondsUntilFree('big');
        $this->clock->sleep(7200);

        self::assertSame([7200, 0], [$heldByThePace, $platform->secondsUntilFree('big')]);
    }

    public function testACooldownAndAHoldOnAnAccountAtBothCeilingsStillWaitForTheLaterReset(): void
    {
        $account = new Account(Provider::ClaudeCode, Account::DEFAULT);
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('secondsUntilFree')->willReturn(60);
        $platform = new AccountFailoverPlatform(Provider::ClaudeCode, [Account::DEFAULT => $this->answering(Account::DEFAULT)], $cooldowns, $this->clock, new NullLogger(), new NoPinnedProviderCooldowns(), $this->ranking());
        $this->readings->record(new WindowReading($account, UsageWindow::Weekly, new Usage(95.0, $this->clock->now()->modify('+5 minutes'))));
        $this->holds->coolDown($account, $this->clock->now()->modify('+2 minutes'));
        $this->readings->record(new WindowReading($account, UsageWindow::FiveHour, new Usage(95.0, $this->clock->now()->modify('+10 minutes'))));

        $bothAtCeiling = $platform->secondsUntilFree('big');
        $this->clock->sleep(300);

        self::assertSame([600, 300], [$bothAtCeiling, $platform->secondsUntilFree('big')]);
    }

    public function testAnAccountCoolingDownAndAtACeilingWaitsBehindAnotherAccountAtASoonerCeiling(): void
    {
        $this->readings->record(new WindowReading(new Account(Provider::ClaudeCode, Account::DEFAULT), UsageWindow::Weekly, new Usage(95.0, $this->clock->now()->modify('+300 seconds'))));
        $this->readings->record(new WindowReading(new Account(Provider::ClaudeCode, 'team'), UsageWindow::Weekly, new Usage(95.0, $this->clock->now()->modify('+120 seconds'))));
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('secondsUntilFree')->willReturnCallback(static fn (Account $account): int => Account::DEFAULT === $account->name ? 60 : 0);

        self::assertSame(120, $this->platform($cooldowns)->secondsUntilFree('big'));
        self::assertSame([], $this->called);
    }

    public function testACallRefusedForACoolingAccountAtAFreshCeilingCarriesTheLaterEnd(): void
    {
        $this->both(Account::DEFAULT, $this->usage(10.0, 5), $this->usage(95.0, 2));
        $this->both('team', $this->usage(95.0, 1), $this->usage(20.0, 60));

        self::assertSame(3600, $this->waitOf($this->platform($this->teamCoolingFor(60))));
        self::assertSame([], $this->called);
    }

    public function testACooldownThatOutlastsTheCeilingOfItsAccountSetsTheWait(): void
    {
        $this->both(Account::DEFAULT, $this->usage(10.0, 5), $this->usage(95.0, 4));
        $this->both('team', $this->usage(95.0, 1), $this->usage(20.0, 60));

        self::assertSame(2 * 3600, $this->waitOf($this->platform($this->teamCoolingFor(2 * 3600))));
    }

    public function testACoolingAccountWhoseFreshCeilingNamesNoResetWaitsTheLongestWindow(): void
    {
        $this->both(Account::DEFAULT, $this->usage(10.0, 5), $this->usage(95.0, 30));
        $this->readings->record(new WindowReading(new Account(Provider::ClaudeCode, 'team'), UsageWindow::FiveHour, new Usage(95.0, null)));

        self::assertSame(AccountCooldowns::LONGEST_SECONDS, $this->waitOf($this->platform($this->teamCoolingFor(60))));
    }

    public function testAHoldWithAnHourLeftMakesTheCallWaitAnHourNotADay(): void
    {
        $this->holds->coolDown(new Account(Provider::ClaudeCode, Account::DEFAULT), $this->clock->now()->modify('+1 hour'));
        $this->both('team', $this->usage(10.0, 5), $this->usage(95.0, 60));

        self::assertSame(3600, $this->waitOf($this->platform()));
    }

    public function testAfterTheOnlyRankedAccountIsLimitedTheWaitSkipsAnAccountStillAtItsCeiling(): void
    {
        $this->both(Account::DEFAULT, $this->usage(10.0, 5), $this->usage(95.0, 2));
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('secondsUntilFree')->willReturnCallback(fn (Account $account): int => 'team' === $account->name && in_array('team', $this->called, true) ? 120 : 0);
        $refusing = MockPlatformFactory::createPlatform(function (): never {
            $this->called[] = 'team';

            throw new RateLimitExceededException(120);
        });
        $platform = new AccountFailoverPlatform(Provider::ClaudeCode, [Account::DEFAULT => $this->answering(Account::DEFAULT), 'team' => $refusing], $cooldowns, $this->clock, new NullLogger(), new NoPinnedProviderCooldowns(), $this->ranking());

        self::assertSame(120, $this->waitOf($platform));
        self::assertSame(['team'], $this->called);
    }

    public function testTierSelectionSeesAProviderWhoseEveryFreeAccountIsAtItsCeilingAsWaiting(): void
    {
        $this->both(Account::DEFAULT, $this->usage(10.0, 5), $this->usage(95.0, 2));
        $this->both('team', $this->usage(10.0, 5), $this->usage(20.0, 60));

        self::assertSame(60, $this->platform($this->teamCoolingFor(60))->secondsUntilFree('big'));
        self::assertSame(0, $this->platform()->secondsUntilFree('big'));
    }

    public function testACallRefusedMidWayOnTheTopRankedAccountStillFailsOverToTheNext(): void
    {
        $this->both(Account::DEFAULT, $this->usage(10.0, 5), $this->usage(20.0, 60));
        $this->both('team', $this->usage(10.0, 1), $this->usage(20.0, 60));
        $platform = new AccountFailoverPlatform(Provider::ClaudeCode, [Account::DEFAULT => $this->answering(Account::DEFAULT), 'team' => $this->answering(static fn (): never => throw new RateLimitExceededException(300))], new NoCooldowns(), $this->clock, new NullLogger(), new NoPinnedProviderCooldowns(), $this->ranking());

        self::assertSame(Account::DEFAULT, $platform->invoke('big', 'Review.')->asText());
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-03-02 10:00:00 UTC');
        $this->readings = new ReadingsInMemory($this->clock);
        $this->holds = new HoldsInMemory($this->clock);
        $this->called = [];
    }

    private function teamCoolingFor(int $seconds): AccountCooldowns
    {
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('secondsUntilFree')->willReturnCallback(static fn (Account $account): int => 'team' === $account->name ? $seconds : 0);

        return $cooldowns;
    }

    private function waitOf(AccountFailoverPlatform $platform): int
    {
        try {
            $platform->invoke('big', 'Review.');
        } catch (EveryAccountCoolingDown $out) {
            return $out->secondsUntilAnAccountIsFree;
        }

        self::fail('The call was answered though no account can take it.');
    }

    private function usage(float $percent, int $resetsInHours): Usage
    {
        return new Usage($percent, $this->clock->now()->modify('+'.$resetsInHours.' hours'));
    }

    private function both(string $name, Usage $short, Usage $weekly): void
    {
        $account = new Account(Provider::ClaudeCode, $name);
        $this->readings->record(new WindowReading($account, UsageWindow::FiveHour, $short));
        $this->readings->record(new WindowReading($account, UsageWindow::Weekly, $weekly));
    }

    private function platform(?AccountCooldowns $cooldowns = null): AccountFailoverPlatform
    {
        return new AccountFailoverPlatform(Provider::ClaudeCode, [Account::DEFAULT => $this->answering(Account::DEFAULT), 'team' => $this->answering('team')], $cooldowns ?? new NoCooldowns(), $this->clock, new NullLogger(), new NoPinnedProviderCooldowns(), $this->ranking());
    }

    private function answering(string|Closure $answer): PlatformInterface
    {
        $name = is_string($answer) ? $answer : 'team';

        return MockPlatformFactory::createPlatform(function () use ($answer, $name) {
            $this->called[] = $name;

            return $answer instanceof Closure ? $answer() : $answer;
        });
    }

    private function ranking(): MeasuredRoomRanking
    {
        return new MeasuredRoomRanking($this->holds, new CeilingStartGuard($this->readings, $this->clock, ['five-hour' => 90.0, 'weekly' => 90.0]));
    }
}
