<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\PinnedProviderCooldownWindows;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PinnedProviderCooldownWindows::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
#[UsesClass(PinnedProvider::class)]
final class PinnedProviderCooldownWindowsTest extends TestCase
{
    private ArrayAdapter $pool;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->clock = new MockClock();
    }

    public function testAPinnedProviderThatWasNeverCooledDownIsUsable(): void
    {
        self::assertFalse($this->cooldowns()->isCoolingDown($this->deepinfra()));
    }

    public function testAPinnedProviderIsCoolingDownWhileItsWindowLasts(): void
    {
        $cooldowns = $this->cooldowns();
        $cooldowns->coolDown($this->deepinfra(), 300);

        $this->clock->sleep(299);

        self::assertTrue($cooldowns->isCoolingDown($this->deepinfra()));
    }

    public function testAPinnedProviderIsUsableAgainOnceTheClockReachesTheEndOfItsWindow(): void
    {
        $cooldowns = $this->cooldowns();
        $cooldowns->coolDown($this->deepinfra(), 300);

        $this->clock->sleep(300);

        self::assertFalse($cooldowns->isCoolingDown($this->deepinfra()));
    }

    public function testEveryWorkerOnTheSharedCacheSeesTheCooldown(): void
    {
        $this->cooldowns()->coolDown($this->deepinfra(), 300);

        self::assertTrue($this->cooldowns()->isCoolingDown($this->deepinfra()));
    }

    public function testTheCooldownOfOneRouterProviderLeavesAnotherOnTheSameAccountUsable(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDown($this->deepinfra(), 300);

        self::assertFalse($cooldowns->isCoolingDown(new PinnedProvider($this->account(), 'together')));
    }

    public function testTheCooldownOfARouterProviderLeavesItUsableOnAnotherAccount(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDown($this->deepinfra(), 300);

        self::assertFalse($cooldowns->isCoolingDown(new PinnedProvider(new Account(Provider::OpenAiCompatible, 'team'), 'deepinfra')));
    }

    public function testARouterProviderNameWithCharactersCacheKeysReserveCoolsDown(): void
    {
        $cooldowns = $this->cooldowns();
        $reserved = new PinnedProvider($this->account(), '{deep}(infra)/@\\');

        $cooldowns->coolDown($reserved, 300);

        self::assertTrue($cooldowns->isCoolingDown($reserved));
    }

    public function testAWindowOfZeroOrLessSecondsCoolsNothingDown(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDown($this->deepinfra(), 0);
        $cooldowns->coolDown($this->deepinfra(), -30);

        self::assertFalse($cooldowns->isCoolingDown($this->deepinfra()));
    }

    public function testAWindowOfOneSecondCoolsDownForThatSecond(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDown($this->deepinfra(), 1);

        self::assertTrue($cooldowns->isCoolingDown($this->deepinfra()));
    }

    public function testAShorterWindowSetWhileOneRunsReplacesIt(): void
    {
        $cooldowns = $this->cooldowns();
        $cooldowns->coolDown($this->deepinfra(), 600);
        $cooldowns->coolDown($this->deepinfra(), 60);

        $this->clock->sleep(60);

        self::assertFalse($cooldowns->isCoolingDown($this->deepinfra()));
    }

    private function cooldowns(): PinnedProviderCooldownWindows
    {
        return new PinnedProviderCooldownWindows($this->pool, $this->clock);
    }

    private function deepinfra(): PinnedProvider
    {
        return new PinnedProvider($this->account(), 'deepinfra');
    }

    private function account(): Account
    {
        return new Account(Provider::OpenAiCompatible, Account::DEFAULT);
    }
}
