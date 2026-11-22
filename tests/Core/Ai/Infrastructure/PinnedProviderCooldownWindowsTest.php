<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\PinnedProviderCooldownNotSaved;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\PinnedProviderCooldownWindows;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(PinnedProviderCooldownWindows::class)]
#[CoversClass(PinnedProviderCooldownNotSaved::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
#[UsesClass(PinnedProvider::class)]
final class PinnedProviderCooldownWindowsTest extends TestCase
{
    private ArrayAdapter $pool;
    private MockClock $clock;
    private LockFactory $locks;
    private ?string $directory = null;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->clock = new MockClock('2026-11-19T12:00:00Z');
        $this->locks = new LockFactory(new InMemoryStore());
    }

    protected function tearDown(): void
    {
        if (null !== $this->directory) {
            new Filesystem()->remove($this->directory);
        }
    }

    public function testAPinnedProviderThatWasNeverCooledDownIsUsable(): void
    {
        self::assertFalse($this->cooldowns()->isCoolingDown($this->deepinfra()));
    }

    public function testThePinsWaitIsRoundedUpToAWholeSecondAndIsZeroOnceItsWindowHasEnded(): void
    {
        $cooldowns = $this->cooldowns();
        $cooldowns->coolDown($this->deepinfra(), 3);

        $this->clock->sleep(1.8);
        $whileCooling = $cooldowns->secondsUntilFree($this->deepinfra());
        $this->clock->sleep(1.2);

        self::assertSame([2, 0], [$whileCooling, $cooldowns->secondsUntilFree($this->deepinfra())]);
    }

    public function testAPinCooledForADayOrLongerAtAFractionalSecondReadsBackAtMostADay(): void
    {
        $this->clock->sleep(0.4);
        $cooldowns = $this->cooldowns();
        $cooldowns->coolDown($this->deepinfra(), 86400);
        $cooledADay = $cooldowns->secondsUntilFree($this->deepinfra());
        $cooldowns->coolDown($this->deepinfra(), 200000);

        self::assertSame([86400, 86400], [$cooledADay, $cooldowns->secondsUntilFree($this->deepinfra())]);
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

    public function testAWorkerWithItsOwnCacheOnTheSharedDirectorySeesTheCooldown(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-pinned-cooldown-'.bin2hex(random_bytes(6));
        $one = new PinnedProviderCooldownWindows(new FilesystemAdapter('', 0, $this->directory), $this->clock, $this->locks);
        $other = new PinnedProviderCooldownWindows(new FilesystemAdapter('', 0, $this->directory), $this->clock, $this->locks);

        $one->coolDown($this->deepinfra(), 300);

        self::assertTrue($other->isCoolingDown($this->deepinfra()));
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

    public function testAShorterWindowSetWhileOneRunsLeavesTheRunningWindow(): void
    {
        $cooldowns = $this->cooldowns();
        $cooldowns->coolDown($this->deepinfra(), 600);
        $cooldowns->coolDown($this->deepinfra(), 60);

        $this->clock->sleep(60);

        self::assertTrue($cooldowns->isCoolingDown($this->deepinfra()));
    }

    public function testALongerWindowSetWhileOneRunsExtendsIt(): void
    {
        $cooldowns = $this->cooldowns();
        $cooldowns->coolDown($this->deepinfra(), 60);
        $cooldowns->coolDown($this->deepinfra(), 600);

        $this->clock->sleep(60);

        self::assertTrue($cooldowns->isCoolingDown($this->deepinfra()));
    }

    public function testAnEarlierEndWrittenLateLeavesTheLaterWindowRunning(): void
    {
        $newer = $this->cooldowns();
        $provider = $this->deepinfra();
        $pool = $this->pool;
        $clock = $this->clock;
        $once = false;
        $delayed = self::createStub(CacheItemPoolInterface::class);
        $delayed->method('getItem')->willReturnCallback(static function (string $key) use ($newer, $provider, $pool, $clock, &$once): CacheItemInterface {
            if (!$once) {
                $once = true;
                $clock->sleep(1);
                $newer->coolDown($provider, 60);
            }

            return $pool->getItem($key);
        });
        $delayed->method('save')->willReturnCallback($pool->save(...));
        $older = new PinnedProviderCooldownWindows($delayed, $clock, new LockFactory(new InMemoryStore()));

        $older->coolDown($provider, 2);
        $clock->sleep(2);

        self::assertTrue($newer->isCoolingDown($provider));
    }

    public function testTheWindowIsSavedWhileTheLockEveryWorkerTakesIsHeld(): void
    {
        $locks = $this->locks;
        $key = 'pinned-provider-cooldown-'.hash('xxh128', $this->deepinfra()->key());
        $pool = $this->pool;
        $heldBySomeoneElse = null;
        $watched = self::createStub(CacheItemPoolInterface::class);
        $watched->method('getItem')->willReturnCallback($pool->getItem(...));
        $watched->method('save')->willReturnCallback(static function (CacheItemInterface $item) use ($locks, $key, $pool, &$heldBySomeoneElse): bool {
            $heldBySomeoneElse = !$locks->createLock($key)->acquire();

            return $pool->save($item);
        });

        new PinnedProviderCooldownWindows($watched, $this->clock, $locks)->coolDown($this->deepinfra(), 60);

        self::assertTrue($heldBySomeoneElse);
    }

    public function testTheLockIsReleasedOnceTheWindowIsSaved(): void
    {
        $this->cooldowns()->coolDown($this->deepinfra(), 60);

        self::assertTrue($this->locks->createLock('pinned-provider-cooldown-'.hash('xxh128', $this->deepinfra()->key()))->acquire());
    }

    public function testAWindowTheCacheRefusesToSaveIsAnError(): void
    {
        $pool = $this->pool;
        $refusing = self::createStub(CacheItemPoolInterface::class);
        $refusing->method('getItem')->willReturnCallback($pool->getItem(...));
        $refusing->method('save')->willReturn(false);

        $this->expectException(PinnedProviderCooldownNotSaved::class);

        new PinnedProviderCooldownWindows($refusing, $this->clock, $this->locks)->coolDown($this->deepinfra(), 60);
    }

    public function testAWindowTheCacheRefusesToSaveReleasesTheLock(): void
    {
        $pool = $this->pool;
        $refusing = self::createStub(CacheItemPoolInterface::class);
        $refusing->method('getItem')->willReturnCallback($pool->getItem(...));
        $refusing->method('save')->willReturn(false);

        try {
            new PinnedProviderCooldownWindows($refusing, $this->clock, $this->locks)->coolDown($this->deepinfra(), 60);
        } catch (PinnedProviderCooldownNotSaved) {
        }

        self::assertTrue($this->locks->createLock('pinned-provider-cooldown-'.hash('xxh128', $this->deepinfra()->key()))->acquire());
    }

    public function testACacheThatFailsWhileSavingIsReportedAsAWindowNotSavedWithItsCause(): void
    {
        $pool = $this->pool;
        $failing = self::createStub(CacheItemPoolInterface::class);
        $failing->method('getItem')->willReturnCallback($pool->getItem(...));
        $cause = new RuntimeException('The disk is full.');
        $failing->method('save')->willThrowException($cause);

        try {
            new PinnedProviderCooldownWindows($failing, $this->clock, $this->locks)->coolDown($this->deepinfra(), 60);
            self::fail('The window was expected not to be saved.');
        } catch (PinnedProviderCooldownNotSaved $notSaved) {
            self::assertSame($cause, $notSaved->getPrevious());
        }
    }

    private function cooldowns(): PinnedProviderCooldownWindows
    {
        return new PinnedProviderCooldownWindows($this->pool, $this->clock, $this->locks);
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
