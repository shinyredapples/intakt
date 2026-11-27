<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\Account;
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
        $this->called = [];
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

    private function platform(): AccountFailoverPlatform
    {
        return new AccountFailoverPlatform(Provider::ClaudeCode, [Account::DEFAULT => $this->answering(Account::DEFAULT), 'team' => $this->answering('team')], new NoCooldowns(), $this->clock, new NullLogger(), new NoPinnedProviderCooldowns(), $this->ranking());
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
        return new MeasuredRoomRanking(new HoldsInMemory($this->clock), new CeilingStartGuard($this->readings, $this->clock, ['five-hour' => 90.0, 'weekly' => 90.0]));
    }
}
