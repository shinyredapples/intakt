<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Domain\RateLimitReading;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\BudgetReserve;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(BudgetReserve::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(RateLimitReading::class)]
#[UsesClass(ForgeRateLimited::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(AppJwt::class)]
final class BudgetReserveTest extends TestCase
{
    private MockClock $clock;
    private ArrayAdapter $pool;
    private LockFactory $locks;
    private RateLimitReadings $readings;

    public function testWithoutAReadingEveryListingIsAdmitted(): void
    {
        $reserve = $this->reserve();

        $reserve->admitListing('alpha');
        $reserve->admitListing('alpha');

        $this->expectNotToPerformAssertions();
    }

    public function testAtTheReserveEveryListingIsAdmitted(): void
    {
        $this->read(remaining: 1000);
        $reserve = $this->reserve();

        $reserve->admitListing('alpha');
        $reserve->admitListing('alpha');

        $this->expectNotToPerformAssertions();
    }

    public function testBelowTheReserveTheSecondListingOfATickWaitsForTheReset(): void
    {
        $this->read(remaining: 999);
        $this->reserve()->admitListing('alpha');
        $this->clock->modify('+29 seconds');

        $limited = $this->refusal($this->reserve(), 'alpha');

        self::assertEquals(new MockClock('2026-11-25 10:30:00 UTC')->now(), $limited->resetsAt);
    }

    public function testBelowTheReserveAListingIsAdmittedAgainOneTickLater(): void
    {
        $this->read(remaining: 999);
        $this->reserve()->admitListing('alpha');
        $this->clock->modify('+60 seconds');

        $this->reserve()->admitListing('alpha');

        $this->expectNotToPerformAssertions();
    }

    public function testBelowTheReserveAListingHalfATickAfterTheLastWaitsForTheReset(): void
    {
        $this->read(remaining: 999);
        $this->reserve()->admitListing('alpha');
        $this->clock->modify('+30 seconds');

        $limited = $this->refusal($this->reserve(), 'alpha');

        self::assertEquals(new MockClock('2026-11-25 10:30:00 UTC')->now(), $limited->resetsAt);
    }

    public function testBelowTheReserveListingsEveryHalfTickAreAdmittedOnlyOncePerTick(): void
    {
        $this->read(remaining: 10);
        $reserve = $this->reserve();
        $admitted = 0;

        foreach (range(1, 4) as $halfTick) {
            try {
                $reserve->admitListing('alpha');
                ++$admitted;
            } catch (ForgeRateLimited) {
            }
            $this->clock->modify('+30 seconds');
        }

        self::assertSame(2, $admitted);
    }

    public function testBelowTheReserveATickThatRanEarlyKeepsItsListingButAddsNoneBeforeTheNextTick(): void
    {
        $this->read(remaining: 999);
        $reserve = $this->reserve();
        $reserve->admitListing('alpha');
        $this->clock->modify('2026-11-25 10:00:59 UTC');
        $reserve->admitListing('alpha');
        $this->clock->modify('2026-11-25 10:01:29 UTC');

        $this->refusal($reserve, 'alpha');
        $this->clock->modify('2026-11-25 10:02:00 UTC');
        $reserve->admitListing('alpha');

        $this->expectNotToPerformAssertions();
    }

    public function testBelowTheReserveAnEarlyListingsDebtOutlivesTheTickItsSlotWasSavedIn(): void
    {
        $this->read(remaining: 1);
        $reserve = $this->reserve();
        $reserve->admitListing('alpha');
        $this->clock->modify('+31 seconds');
        $reserve->admitListing('alpha');
        $this->clock->modify('+61 seconds');
        $reserve->admitListing('alpha');
        $this->clock->modify('+31 seconds');

        $this->expectException(ForgeRateLimited::class);

        $reserve->admitListing('alpha');
    }

    public function testBelowTheReserveTheSlotOfAnEarlyListingLivesUntilItsDueTime(): void
    {
        $this->read(remaining: 999);
        $reserve = $this->reserve();
        $reserve->admitListing('alpha');
        $this->clock->modify('2026-11-25 10:00:59 UTC');
        $reserve->admitListing('alpha');
        $this->clock->modify('2026-11-25 10:01:59.500 UTC');

        $slot = $this->pool->getItem(BudgetReserve::slotKey('678', 'alpha'));

        self::assertSame(new MockClock('2026-11-25 10:02:00 UTC')->now()->getTimestamp(), $slot->get());
    }

    public function testBelowTheReserveTheNextTicksListingIsAdmittedWhenTheEarlierTickRanAFewSecondsLate(): void
    {
        $this->clock->modify('2026-11-25 10:00:03.200 UTC');
        $this->read(remaining: 900);
        $reserve = $this->reserve();
        $reserve->admitListing('beta');
        $this->clock->modify('2026-11-25 10:01:00.500 UTC');

        $reserve->admitListing('beta');

        $this->expectNotToPerformAssertions();
    }

    public function testBelowTheReserveEachProjectKeepsTheListingOfItsTick(): void
    {
        $this->read(remaining: 999);
        $reserve = $this->reserve();

        $reserve->admitListing('alpha');
        $reserve->admitListing('beta');

        $this->expectNotToPerformAssertions();
    }

    public function testBelowTheReserveEveryWatchedProjectIsListedOnEveryTick(): void
    {
        $this->read(remaining: 900);
        $reserve = $this->reserve();
        $listed = ['alpha' => 0, 'beta' => 0];

        foreach (range(1, 10) as $tick) {
            foreach (array_keys($listed) as $project) {
                $reserve->admitListing($project);
                ++$listed[$project];
            }
            $this->clock->modify('+1 minute');
        }

        self::assertSame(['alpha' => 10, 'beta' => 10], $listed);
    }

    public function testProjectNamesThatJoinAlikeKeepSeparateSlots(): void
    {
        self::assertNotSame(BudgetReserve::slotKey('678', '1:a'), BudgetReserve::slotKey('6781:', 'a'));
    }

    public function testOnceTheResetHasPassedListingsAreAdmittedAgain(): void
    {
        $this->read(remaining: 10);
        $this->reserve()->admitListing('alpha');
        $this->clock->modify('+30 minutes');

        $reserve = $this->reserve();
        $reserve->admitListing('alpha');
        $reserve->admitListing('alpha');

        $this->expectNotToPerformAssertions();
    }

    public function testAListingWhileAnotherProcessTakesTheTicksListingWaitsForTheReset(): void
    {
        $this->read(remaining: 999);
        $taking = $this->locks->createLock(BudgetReserve::slotKey('678', 'alpha'));
        $taking->acquire();

        $limited = $this->refusal($this->reserve(), 'alpha');
        $taking->release();

        self::assertEquals(new MockClock('2026-11-25 10:30:00 UTC')->now(), $limited->resetsAt);
    }

    public function testAnotherInstallationKeepsItsListings(): void
    {
        $this->read(remaining: 1);
        $other = $this->reserve(installationId: '999');

        $other->admitListing('alpha');
        $other->admitListing('alpha');

        $this->expectNotToPerformAssertions();
    }

    public function testAReserveOfNothingHoldsNoListingBack(): void
    {
        $this->read(remaining: 1);
        $reserve = $this->reserve(percent: 0);

        $reserve->admitListing('alpha');
        $reserve->admitListing('alpha');

        $this->expectNotToPerformAssertions();
    }

    public function testAReserveOfTheWholeHourHoldsBackOnceOneCallIsSpent(): void
    {
        $this->read(remaining: 4999);
        $this->reserve(percent: 100)->admitListing('alpha');

        $this->expectException(ForgeRateLimited::class);

        $this->reserve(percent: 100)->admitListing('alpha');
    }

    public function testBelowTheReserveAListingWhoseSlotCannotBeRecordedWaitsForTheReset(): void
    {
        $this->read(remaining: 999);
        $unwritable = self::createStub(CacheItemPoolInterface::class);
        $unwritable->method('getItem')->willReturnCallback(new ArrayAdapter()->getItem(...));
        $unwritable->method('save')->willReturn(false);

        $limited = $this->refusal($this->reserve(pool: $unwritable), 'alpha');

        self::assertEquals(new MockClock('2026-11-25 10:30:00 UTC')->now(), $limited->resetsAt);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function sharesOutsideTheHour(): iterable
    {
        yield 'below nothing' => [-1];
        yield 'above the whole hour' => [101];
    }

    #[DataProvider('sharesOutsideTheHour')]
    public function testAReserveOutsideNothingToTheWholeHourIsRefused(int $percent): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->reserve(percent: $percent);
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
        // The pool expires slots by the test clock, so the seconds a slot lives are the seconds the clock moves.
        $this->pool = new ArrayAdapter(clock: $this->clock);
        $this->locks = new LockFactory(new InMemoryStore());
        $this->readings = new RateLimitReadings(new ArrayAdapter(clock: $this->clock), $this->clock, new LockFactory(new InMemoryStore()));
    }

    private function read(int $remaining): void
    {
        $reset = new MockClock('2026-11-25 10:30:00 UTC')->now()->getTimestamp();

        $this->readings->observe('678', 200, ['x-ratelimit-remaining' => [(string) $remaining], 'x-ratelimit-limit' => ['5000'], 'x-ratelimit-reset' => [(string) $reset]]);
    }

    private function refusal(BudgetReserve $reserve, string $project): ForgeRateLimited
    {
        try {
            $reserve->admitListing($project);
        } catch (ForgeRateLimited $limited) {
            return $limited;
        }

        throw new LogicException('The listing was expected to wait for the reset.');
    }

    private function reserve(string $installationId = '678', int $percent = FreshReadings::RESERVE_PERCENT, ?CacheItemPoolInterface $pool = null): BudgetReserve
    {
        $jwt = new AppJwt('12345', AppKey::generate()->private, $this->clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, new RecordedInstallation('installation-secret')->client(), new NullLogger()), $installationId, $this->clock);

        return new BudgetReserve($pool ?? $this->pool, $this->readings, $tokens, $this->clock, $this->locks, $percent);
    }
}
