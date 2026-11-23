<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Domain;

use DateTimeInterface;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Budget\Domain\OutageWatch;
use Intakt\Core\Budget\Domain\UsageReadingsStayedAway;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(OutageWatch::class)]
#[CoversClass(UsageReadingsStayedAway::class)]
#[UsesClass(Account::class)]
#[UsesClass(Usage::class)]
#[UsesClass(UsageWindow::class)]
#[UsesClass(WindowReading::class)]
#[UsesClass(WindowUse::class)]
final class OutageWatchTest extends TestCase
{
    private MockClock $clock;
    private ReadingsInMemory $readings;
    private OutageWatch $watch;

    /** @var list<string> */
    private array $outages = [];

    public function testAReadingOlderThanThreeHoursIsAnOutageSinceThatReading(): void
    {
        $this->clock->sleep(OutageWatch::OUTAGE_SECONDS);

        $this->watch->watch($this->claude('main'));

        self::assertSame(['claude_code.main since 2026-11-02T08:00:00+00:00, seen 2026-11-02T11:00:00+00:00'], $this->outages);
    }

    public function testAReadingYoungerThanThreeHoursIsNoOutage(): void
    {
        $this->clock->sleep(OutageWatch::OUTAGE_SECONDS - 1);

        $this->watch->watch($this->claude('main'));

        self::assertSame([], $this->outages);
    }

    public function testTheNewestReadingOfEitherWindowEndsAnOutage(): void
    {
        $this->clock->sleep(OutageWatch::OUTAGE_SECONDS);
        $this->readings->record(new WindowReading($this->claude('main'), UsageWindow::Weekly, new Usage(11.0, $this->clock->now()->modify('+5 days'))));

        $this->watch->watch($this->claude('main'));

        self::assertSame([], $this->outages);
    }

    // Each window's age is sampled at its own clock reading, so a pause between the lookups can make the older reading look newer by age.
    public function testTheOutageStartsFromTheLatestReadingWhenTheClockAdvancesBetweenWindowLookups(): void
    {
        $this->readings->record(new WindowReading($this->claude('solo'), UsageWindow::FiveHour, new Usage(10.0, null)));
        $this->clock->sleep(1);
        $this->readings->record(new WindowReading($this->claude('solo'), UsageWindow::Weekly, new Usage(5.0, null)));
        $this->clock->sleep(OutageWatch::OUTAGE_SECONDS);
        $clock = $this->clock;
        $readings = $this->readings;
        $slowLookups = self::createStub(MeasuredUse::class);
        $slowLookups->method('of')->willReturnCallback(static function (Account $account, UsageWindow $window) use ($clock, $readings): ?WindowUse {
            $use = $readings->of($account, $window);
            $clock->sleep(2);

            return $use;
        });
        $events = new EventDispatcher();
        $events->addListener(UsageReadingsStayedAway::class, function (UsageReadingsStayedAway $outage): void {
            $this->outages[] = $outage->lastReadingAt->format(DateTimeInterface::ATOM);
        });

        new OutageWatch($slowLookups, $this->clock, $events)->watch($this->claude('solo'));

        self::assertSame(['2026-11-02T08:00:01+00:00'], $this->outages);
    }

    public function testAnAccountNeverReadHasNoOutage(): void
    {
        $this->clock->sleep(OutageWatch::OUTAGE_SECONDS);

        $this->watch->watch($this->claude('new'));

        self::assertSame([], $this->outages);
    }

    public function testAnOutageIsReportedOnlyForTheAccountWatched(): void
    {
        $this->clock->sleep(OutageWatch::OUTAGE_SECONDS);
        $this->readLow($this->claude('team'));

        $this->watch->watch($this->claude('team'));

        self::assertSame([], $this->outages);
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-02 08:00:00 UTC');
        $this->readings = new ReadingsInMemory($this->clock);
        $events = new EventDispatcher();
        $events->addListener(UsageReadingsStayedAway::class, function (UsageReadingsStayedAway $outage): void {
            $this->outages[] = sprintf('%s since %s, seen %s', $outage->account->key(), $outage->lastReadingAt->format(DateTimeInterface::ATOM), $outage->seenAt->format(DateTimeInterface::ATOM));
        });
        $this->watch = new OutageWatch($this->readings, $this->clock, $events);
        $this->readLow($this->claude('main'));
        $this->readLow($this->claude('team'));
    }

    private function readLow(Account $account): void
    {
        $this->readings->record(new WindowReading($account, UsageWindow::FiveHour, new Usage(10.0, $this->clock->now()->modify('+4 hours'))));
        $this->readings->record(new WindowReading($account, UsageWindow::Weekly, new Usage(5.0, $this->clock->now()->modify('+6 days'))));
    }

    private function claude(string $name): Account
    {
        return new Account(Provider::ClaudeCode, $name);
    }
}
