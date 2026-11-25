<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Domain\RateLimitReading;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

#[CoversClass(RateLimitReadings::class)]
#[UsesClass(RateLimitReading::class)]
#[UsesClass(ForgeRateLimited::class)]
final class RateLimitReadingsTest extends TestCase
{
    private string $directory;
    private MockClock $clock;

    public function testAReadingRecordedByOneProcessIsReadByAnother(): void
    {
        $reset = $this->clock->now()->getTimestamp() + 600;

        $this->process()->observe('678', 200, ['x-ratelimit-remaining' => ['4000'], 'x-ratelimit-limit' => ['5000'], 'x-ratelimit-reset' => [(string) $reset]]);

        $reading = $this->process()->latest('678');
        self::assertSame(4000, $reading?->remaining);
        self::assertSame(5000, $reading->limit);
        self::assertSame($reset, $reading->resetsAt->getTimestamp());
    }

    public function testAnAnswerWithoutItsHourlyLimitRecordsNoReading(): void
    {
        $reset = $this->clock->now()->getTimestamp() + 600;

        $this->process()->observe('678', 200, ['x-ratelimit-remaining' => ['4000'], 'x-ratelimit-reset' => [(string) $reset]]);

        self::assertNull($this->process()->latest('678'));
    }

    public function testAPauseSetByOneProcessStopsAnother(): void
    {
        $this->limitOf($this->process(), 429, ['retry-after' => ['90']]);

        $this->expectException(ForgeRateLimited::class);

        $this->process()->refuseWhileLimited('678', RateLimitReadings::CORE);
    }

    public function testAnotherInstallationIsNotPaused(): void
    {
        $this->limitOf($this->process(), 429, ['retry-after' => ['90']]);

        $this->process()->refuseWhileLimited('999', RateLimitReadings::CORE);

        self::assertNull($this->process()->latest('999'));
    }

    public function testAReadingIsForgottenOnceItsResetHasPassed(): void
    {
        $this->process()->observe('678', 200, ['x-ratelimit-remaining' => ['10'], 'x-ratelimit-limit' => ['5000'], 'x-ratelimit-reset' => [(string) ($this->clock->now()->getTimestamp() + 60)]]);

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

        $this->process()->refuseWhileLimited('678', RateLimitReadings::CORE);
    }

    public function testARetryAfterGivenAsADateIsARateLimitUntilThatMoment(): void
    {
        $limited = $this->limitOf($this->process(), 403, ['retry-after' => [$this->clock->now()->modify('+5 minutes')->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s \G\M\T')]]);

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

        $this->process()->refuseWhileLimited('678', RateLimitReadings::CORE);
    }

    public function testA403WithCallsLeftAndNoRetryAfterIsNoRateLimit(): void
    {
        $reset = (string) ($this->clock->now()->getTimestamp() + 600);

        $this->process()->observe('678', 403, ['x-ratelimit-remaining' => ['12'], 'x-ratelimit-limit' => ['5000'], 'x-ratelimit-reset' => [$reset]]);
        $this->process()->refuseWhileLimited('678', RateLimitReadings::CORE);

        self::assertSame(12, $this->process()->latest('678')?->remaining);
    }

    public function testAnAnswerFromTheGraphqlPoolLeavesTheRestReadingInPlace(): void
    {
        $reset = (string) ($this->clock->now()->getTimestamp() + 600);
        $this->process()->observe('678', 200, ['x-ratelimit-remaining' => ['4000'], 'x-ratelimit-limit' => ['5000'], 'x-ratelimit-reset' => [$reset], 'x-ratelimit-resource' => ['core']]);

        $this->process()->observe('678', 200, ['x-ratelimit-remaining' => ['3'], 'x-ratelimit-limit' => ['5000'], 'x-ratelimit-reset' => [$reset], 'x-ratelimit-resource' => ['graphql']]);

        self::assertSame(4000, $this->process()->latest('678')?->remaining);
    }

    public function testAnExhaustedGraphqlPoolDoesNotPauseRestCalls(): void
    {
        $this->limitOf($this->process(), 403, $this->exhausted('graphql'));

        $this->process()->refuseWhileLimited('678', RateLimitReadings::CORE);

        self::assertNull($this->process()->latest('678'));
    }

    public function testAnExhaustedGraphqlPoolPausesGraphqlCalls(): void
    {
        $this->limitOf($this->process(), 403, $this->exhausted('graphql'));

        $this->expectException(ForgeRateLimited::class);

        $this->process()->refuseWhileLimited('678', RateLimitReadings::GRAPHQL);
    }

    public function testAnExhaustedGraphqlPoolAnsweredWith200PausesGraphqlCalls(): void
    {
        $limited = $this->limitOf($this->process(), 200, $this->exhausted('graphql'));

        self::assertSame($this->clock->now()->getTimestamp() + 3600, $limited->resetsAt->getTimestamp());
        $this->expectException(ForgeRateLimited::class);

        $this->process()->refuseWhileLimited('678', RateLimitReadings::GRAPHQL);
    }

    public function testAnExhaustedGraphqlPoolAnsweredWith200DoesNotPauseRestCalls(): void
    {
        $this->limitOf($this->process(), 200, $this->exhausted('graphql'));

        $this->process()->refuseWhileLimited('678', RateLimitReadings::CORE);

        self::assertNull($this->process()->latest('678'));
    }

    public function testAGraphqlAnswerWith200AndARetryAfterButBudgetLeftIsNoRateLimit(): void
    {
        $reset = (string) ($this->clock->now()->getTimestamp() + 600);

        $this->process()->observe('678', 200, ['retry-after' => ['60'], 'x-ratelimit-remaining' => ['3'], 'x-ratelimit-reset' => [$reset], 'x-ratelimit-resource' => ['graphql']]);
        $this->process()->refuseWhileLimited('678', RateLimitReadings::GRAPHQL);

        self::assertNull($this->process()->latest('678'));
    }

    public function testARestAnswerWith200ThatSpentTheLastCallIsNoRateLimit(): void
    {
        $this->process()->observe('678', 200, $this->exhausted('core'));
        $this->process()->refuseWhileLimited('678', RateLimitReadings::CORE);

        self::assertSame(0, $this->process()->latest('678')?->remaining);
    }

    public function testAnUpgradeKeepsAnUnexpiredPauseStoredUnderTheEarlierKey(): void
    {
        $this->pauseStoredByAnEarlierVersion();

        $this->expectException(ForgeRateLimited::class);

        $this->process()->refuseWhileLimited('678', RateLimitReadings::CORE);
    }

    public function testAPauseStoredUnderTheEarlierKeyIsNotShortenedByALaterLimit(): void
    {
        $this->pauseStoredByAnEarlierVersion();

        $later = $this->limitOf($this->process(), 429, ['retry-after' => ['30']]);

        self::assertSame($this->clock->now()->getTimestamp() + 3600, $later->resetsAt->getTimestamp());
    }

    public function testAPauseStoredUnderTheEarlierKeyDoesNotHoldGraphqlQueries(): void
    {
        $this->pauseStoredByAnEarlierVersion();

        $this->process()->refuseWhileLimited('678', RateLimitReadings::GRAPHQL);

        self::assertNull($this->process()->latest('678'));
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-rate-limits-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        // The files expire on the wall clock, which no test clock reaches, so the test clock starts at the wall clock's current second.
        $this->clock = new MockClock('@'.new NativeClock()->now()->getTimestamp());
    }

    protected function tearDown(): void
    {
        new FilesystemAdapter('', 0, $this->directory)->clear();
    }

    // Written under the kind and the hashed installation id alone, because that is the key the version before the per-pool keys used.
    private function pauseStoredByAnEarlierVersion(): void
    {
        $cache = new FilesystemAdapter('', 0, $this->directory);
        $until = $this->clock->now()->modify('+1 hour');

        $cache->save($cache->getItem('github-rate-limit-limited-'.hash('sha256', '678'))->set($until->getTimestamp())->expiresAt($until));
    }

    /**
     * @return array<string, list<string>>
     */
    private function exhausted(string $pool): array
    {
        $reset = (string) ($this->clock->now()->getTimestamp() + 3600);

        return ['x-ratelimit-remaining' => ['0'], 'x-ratelimit-limit' => ['5000'], 'x-ratelimit-reset' => [$reset], 'x-ratelimit-resource' => [$pool]];
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
