<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeBudget;
use Intakt\Plugin\GitHubForge\Domain\RateLimitReading;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\BudgetReserve;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubForgeBudgets;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(GitHubForgeBudgets::class)]
#[UsesClass(ForgeBudget::class)]
#[UsesClass(BudgetReserve::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(RateLimitReading::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(AppJwt::class)]
final class GitHubForgeBudgetsTest extends TestCase
{
    private MockClock $clock;
    private RateLimitReadings $readings;

    public function testAnInstallWithoutAGitHubAppShowsNoBudget(): void
    {
        self::assertSame([], $this->budgets('')->thisHour());
    }

    public function testAnInstallationWithoutAReadingThisHourIsShownUnread(): void
    {
        $budgets = $this->budgets('678')->thisHour();

        self::assertCount(1, $budgets);
        self::assertSame('678', $budgets[0]->installation);
        self::assertFalse($budgets[0]->isRead());
        self::assertFalse($budgets[0]->reserveInForce);
    }

    public function testTheBudgetCountsTheCallsUsedAndRemainingOfTheLatestReading(): void
    {
        $this->read(remaining: 4200);

        $budget = $this->budgets('678')->thisHour()[0];

        self::assertTrue($budget->isRead());
        self::assertSame(800, $budget->used);
        self::assertSame(4200, $budget->remaining);
        self::assertFalse($budget->reserveInForce);
    }

    public function testTheReserveIsInForceBelowItsShareOfTheLimit(): void
    {
        $this->read(remaining: 999);

        self::assertTrue($this->budgets('678')->thisHour()[0]->reserveInForce);
    }

    public function testTheReserveIsNotInForceAtExactlyItsShareOfTheLimit(): void
    {
        $this->read(remaining: 1000);

        self::assertFalse($this->budgets('678')->thisHour()[0]->reserveInForce);
    }

    public function testARemainingAboveTheLimitCountsNoCallUsed(): void
    {
        $this->read(remaining: 5100);

        self::assertSame(0, $this->budgets('678')->thisHour()[0]->used);
    }

    public function testAReadingWhoseHourHasPassedIsShownUnread(): void
    {
        $this->read(remaining: 999);
        $this->clock->modify('+31 minutes');

        $budget = $this->budgets('678')->thisHour()[0];

        self::assertFalse($budget->isRead());
        self::assertFalse($budget->reserveInForce);
    }

    public function testAnotherInstallationsReadingIsNotShown(): void
    {
        $this->read(remaining: 999);

        self::assertFalse($this->budgets('679')->thisHour()[0]->isRead());
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
        $this->readings = FreshReadings::at($this->clock);
    }

    private function read(int $remaining): void
    {
        $reset = new MockClock('2026-11-25 10:30:00 UTC')->now()->getTimestamp();

        $this->readings->observe('678', 200, ['x-ratelimit-remaining' => [(string) $remaining], 'x-ratelimit-limit' => ['5000'], 'x-ratelimit-reset' => [(string) $reset]]);
    }

    private function budgets(string $installationId): GitHubForgeBudgets
    {
        $jwt = new AppJwt('12345', 'never-signed', $this->clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, new MockHttpClient(), new NullLogger()), $installationId, $this->clock);
        $reserve = new BudgetReserve(new ArrayAdapter(), $this->readings, $tokens, $this->clock, new LockFactory(new InMemoryStore()), FreshReadings::RESERVE_PERCENT);

        return new GitHubForgeBudgets($tokens, $this->readings, $reserve);
    }
}
