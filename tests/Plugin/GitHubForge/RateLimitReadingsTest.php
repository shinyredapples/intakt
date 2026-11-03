<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Domain\RateLimitReading;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

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
        mkdir($this->directory);
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
        $this->limitOf($this->process(), 429, ['retry-after' => ['90']]);

        $this->expectException(ForgeRateLimited::class);

        $this->process()->refuseWhileLimited('678');
    }

    public function testAnotherInstallationIsNotPaused(): void
    {
        $this->limitOf($this->process(), 429, ['retry-after' => ['90']]);

        $this->process()->refuseWhileLimited('999');

        self::assertNull($this->process()->latest('999'));
    }

    public function testAReadingIsForgottenOnceItsResetHasPassed(): void
    {
        $this->process()->observe('678', 200, ['x-ratelimit-remaining' => ['10'], 'x-ratelimit-reset' => [(string) ($this->clock->now()->getTimestamp() + 60)]]);

        $this->clock->modify('+2 minutes');

        self::assertNull($this->process()->latest('678'));
    }

    public function testANamedWaitLongerThanAnHourIsKeptWhole(): void
    {
        $limited = $this->limitOf($this->process(), 429, ['retry-after' => ['7200']]);

        self::assertSame($this->clock->now()->getTimestamp() + 7200, $limited->resetsAt->getTimestamp());
    }

    public function testALaterShorterLimitDoesNotEndALongerPause(): void
    {
        $this->limitOf($this->process(), 429, ['retry-after' => ['600']]);
        $later = $this->limitOf($this->process(), 429, ['retry-after' => ['30']]);
        $this->clock->modify('+31 seconds');

        self::assertSame($this->clock->now()->getTimestamp() + 569, $later->resetsAt->getTimestamp());
        $this->expectException(ForgeRateLimited::class);

        $this->process()->refuseWhileLimited('678');
    }

    public function testARetryAfterGivenAsADateIsARateLimitUntilThatMoment(): void
    {
        $limited = $this->limitOf($this->process(), 403, ['retry-after' => ['Wed, 25 Nov 2026 10:05:00 GMT']]);

        self::assertSame($this->clock->now()->getTimestamp() + 300, $limited->resetsAt->getTimestamp());
    }

    public function testAnUnreadableRetryAfterPausesForOneMinute(): void
    {
        $limited = $this->limitOf($this->process(), 429, ['retry-after' => ['soon']]);

        self::assertSame($this->clock->now()->getTimestamp() + 60, $limited->resetsAt->getTimestamp());
    }

    public function testAPrimaryLimitWithAResetAlreadyPassedPausesForOneMinute(): void
    {
        $past = (string) ($this->clock->now()->getTimestamp() - 5);

        $limited = $this->limitOf($this->process(), 403, ['x-ratelimit-remaining' => ['0'], 'x-ratelimit-reset' => [$past]]);

        self::assertSame($this->clock->now()->getTimestamp() + 60, $limited->resetsAt->getTimestamp());
        $this->expectException(ForgeRateLimited::class);

        $this->process()->refuseWhileLimited('678');
    }

    public function testA403WithCallsLeftAndNoRetryAfterIsNoRateLimit(): void
    {
        $reset = (string) ($this->clock->now()->getTimestamp() + 600);

        $this->process()->observe('678', 403, ['x-ratelimit-remaining' => ['12'], 'x-ratelimit-reset' => [$reset]]);
        $this->process()->refuseWhileLimited('678');

        self::assertSame(12, $this->process()->latest('678')?->remaining);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function limitOf(RateLimitReadings $process, int $status, array $headers): ForgeRateLimited
    {
        try {
            $process->observe('678', $status, $headers);
        } catch (ForgeRateLimited $limited) {
            return $limited;
        }

        throw new LogicException('The answer was expected to be a rate limit.');
    }

    private function process(): RateLimitReadings
    {
        return new RateLimitReadings(new FilesystemAdapter('', 0, $this->directory), $this->clock, new LockFactory(new FlockStore($this->directory)));
    }
}
