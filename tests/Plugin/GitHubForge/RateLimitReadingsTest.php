<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Domain\RateLimitReading;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(RateLimitReadings::class)]
#[UsesClass(RateLimitReading::class)]
#[UsesClass(ForgeRateLimited::class)]
final class RateLimitReadingsTest extends TestCase
{
    private string $directory;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-rate-limits-'.bin2hex(random_bytes(4));
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
    }

    protected function tearDown(): void
    {
        new FilesystemAdapter('', 0, $this->directory)->clear();
    }

    public function testAReadingRecordedByOneProcessIsReadByAnother(): void
    {
        $reset = $this->clock->now()->getTimestamp() + 600;

        $this->process()->observe('678', 200, ['x-ratelimit-remaining' => ['4000'], 'x-ratelimit-reset' => [(string) $reset]]);

        $reading = $this->process()->latest('678');
        self::assertSame(4000, $reading?->remaining);
        self::assertSame($reset, $reading->resetsAt->getTimestamp());
    }

    public function testAPauseSetByOneProcessStopsAnother(): void
    {
        try {
            $this->process()->observe('678', 429, ['retry-after' => ['90']]);
        } catch (ForgeRateLimited) {
        }

        $this->expectException(ForgeRateLimited::class);

        $this->process()->refuseWhileLimited('678');
    }

    public function testAnotherInstallationIsNotPaused(): void
    {
        try {
            $this->process()->observe('678', 429, ['retry-after' => ['90']]);
        } catch (ForgeRateLimited) {
        }

        $this->process()->refuseWhileLimited('999');

        self::assertNull($this->process()->latest('999'));
    }

    public function testAReadingIsForgottenOnceItsResetHasPassed(): void
    {
        $this->process()->observe('678', 200, ['x-ratelimit-remaining' => ['10'], 'x-ratelimit-reset' => [(string) ($this->clock->now()->getTimestamp() + 60)]]);

        $this->clock->modify('+2 minutes');

        self::assertNull($this->process()->latest('678'));
    }

    private function process(): RateLimitReadings
    {
        return new RateLimitReadings(new FilesystemAdapter('', 0, $this->directory), $this->clock);
    }
}
