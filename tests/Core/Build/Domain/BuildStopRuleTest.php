<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Build\Domain\BuildLimits;
use Intakt\Core\Build\Domain\BuildProgress;
use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Build\Domain\BuildStopRule;
use Intakt\Core\Build\Domain\BuildWatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

#[CoversClass(BuildStopRule::class)]
#[UsesClass(BuildLimits::class)]
#[UsesClass(BuildProgress::class)]
#[UsesClass(BuildWatch::class)]
#[UsesClass(BuildStopCause::class)]
final class BuildStopRuleTest extends TestCase
{
    private const int ALLOWANCE = 1_000;

    private const int IDLE_SECONDS = 600;

    private const int CEILING = 4_000;

    private const int TASK_TOTAL = 10_000;

    private MockClock $clock;

    private BuildStopRule $rule;

    public function testABuildOverItsAllowanceThatKeepsMakingProgressContinues(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(
            tokens: 900,
            content: 'a',
        ));

        $this->clock->sleep(500);
        $watch = $this->rule->observe($watch, $this->progress(
            tokens: 1_500,
            content: 'b',
        ));
        $this->clock->sleep(500);
        $watch = $this->rule->observe($watch, $this->progress(
            tokens: 2_000,
            content: 'c',
        ));
        $this->clock->sleep(500);

        self::assertNull($this->rule->stopCause($watch));
    }

    public function testABuildUnderItsAllowanceIsNeverStoppedForLackOfProgress(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(
            tokens: 100,
            content: 'a',
        ));

        $this->clock->sleep(self::IDLE_SECONDS * 10);
        $watch = $this->rule->observe($watch, $this->progress(
            tokens: 900,
            content: 'a',
        ));

        self::assertNull($this->rule->stopCause($watch));
    }

    public function testAContentChangingCommitResetsTheIdleTime(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(
            tokens: 1_200,
            content: 'a',
        ));

        $this->clock->sleep(self::IDLE_SECONDS - 1);
        $watch = $this->rule->observe($watch, $this->progress(
            tokens: 1_300,
            content: 'b',
        ));
        $this->clock->sleep(self::IDLE_SECONDS - 1);

        self::assertNull($this->rule->stopCause($watch));
    }

    public function testAChangeInTheSetOfFailingTestsResetsTheIdleTime(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->failing(1_200, ['testA' => 'failed', 'testB' => 'failed']));

        $this->clock->sleep(self::IDLE_SECONDS - 1);
        $watch = $this->rule->observe($watch, $this->failing(1_300, ['testB' => 'failed']));
        $this->clock->sleep(self::IDLE_SECONDS - 1);

        self::assertNull($this->rule->stopCause($watch));
    }

    public function testAnEmptyCommitDoesNotResetTheIdleTime(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(
            tokens: 1_200,
            content: 'a',
        ));

        $this->clock->sleep(self::IDLE_SECONDS - 1);
        $watch = $this->rule->observe($watch, $this->progress(
            tokens: 1_300,
            content: 'a',
        ));
        $this->clock->sleep(1);

        self::assertSame(BuildStopCause::NoProgress, $this->rule->stopCause($watch));
    }

    public function testARewordedFailureOfTheSameTestDoesNotResetTheIdleTime(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->failing(1_200, ['testA' => 'expected 1, got 2', 'testB' => 'timed out']));

        $this->clock->sleep(self::IDLE_SECONDS - 1);
        $watch = $this->rule->observe($watch, $this->failing(1_300, ['testB' => 'timed out after 30 s', 'testA' => 'expected 1, got 3']));
        $this->clock->sleep(1);

        self::assertSame(BuildStopCause::NoProgress, $this->rule->stopCause($watch));
    }

    public function testABuildOverItsAllowanceWithNoProgressJustBeforeTheIdleLimitContinues(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(
            tokens: 1_200,
            content: 'a',
        ));

        $this->clock->sleep(self::IDLE_SECONDS - 1);
        $watch = $this->rule->observe($watch, $this->progress(
            tokens: 1_300,
            content: 'a',
        ));

        self::assertNull($this->rule->stopCause($watch));
    }

    public function testABuildIsNotStoppedAFractionOfASecondBeforeTheIdleLimit(): void
    {
        $this->clock->sleep(0.9);
        $watch = $this->rule->startWatching($this->limits(), $this->progress(
            tokens: 1_200,
            content: 'a',
        ));

        $this->clock->sleep(self::IDLE_SECONDS - 1);
        $this->clock->sleep(0.2);

        self::assertNull($this->rule->stopCause($watch));
    }

    public function testReorderingNumericLookingTestNamesIsNoProgress(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->failing(1_200, ['01' => 'failed', '1e0' => 'failed']));

        $this->clock->sleep(self::IDLE_SECONDS - 1);
        $watch = $this->rule->observe($watch, $this->failing(1_300, ['1e0' => 'failed', '01' => 'failed']));
        $this->clock->sleep(1);

        self::assertSame(BuildStopCause::NoProgress, $this->rule->stopCause($watch));
    }

    public function testABuildOverItsAllowanceWithNoProgressAtTheIdleLimitStopsWithNoProgress(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(
            tokens: 1_200,
            content: 'a',
        ));

        $this->clock->sleep(self::IDLE_SECONDS);
        $watch = $this->rule->observe($watch, $this->progress(
            tokens: 1_300,
            content: 'a',
        ));

        self::assertSame(BuildStopCause::NoProgress, $this->rule->stopCause($watch));
    }

    public function testOneElapsedSecondAtTheEndOfDaylightSavingTimeCountsAsOneSecond(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $clock = new class implements ClockInterface {
            public DateTimeImmutable $instant;

            public function now(): DateTimeImmutable
            {
                return $this->instant;
            }
        };
        $clock->instant = new DateTimeImmutable('2026-11-01T05:59:59Z')->setTimezone($zone);
        $rule = new BuildStopRule($clock);
        $watch = $rule->startWatching(new BuildLimits(10, 1, 100, 200), new BuildProgress(10, 10, 'same', [], false));

        $clock->instant = new DateTimeImmutable('2026-11-01T06:00:00Z')->setTimezone($zone);

        self::assertSame(BuildStopCause::NoProgress, $rule->stopCause($watch));
    }

    public function testABuildThatCrossesItsAllowanceLongAfterItsLastProgressStopsWithNoProgress(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(
            tokens: 100,
            content: 'a',
        ));

        $this->clock->sleep(self::IDLE_SECONDS);
        $watch = $this->rule->observe($watch, $this->progress(
            tokens: self::ALLOWANCE,
            content: 'a',
        ));

        self::assertSame(BuildStopCause::NoProgress, $this->rule->stopCause($watch));
    }

    public function testABuildReachingTheRunawayCeilingStopsWhileStillMakingProgress(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(
            tokens: 3_000,
            content: 'a',
        ));

        $this->clock->sleep(10);
        $watch = $this->rule->observe($watch, $this->progress(
            tokens: self::CEILING,
            content: 'b',
        ));

        self::assertSame(BuildStopCause::RunawayCeiling, $this->rule->stopCause($watch));
    }

    public function testABuildJustBelowTheRunawayCeilingThatMakesProgressContinues(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(
            tokens: 3_000,
            content: 'a',
        ));

        $this->clock->sleep(10);
        $watch = $this->rule->observe($watch, $this->progress(
            tokens: self::CEILING - 1,
            content: 'b',
        ));

        self::assertNull($this->rule->stopCause($watch));
    }

    public function testATaskReachingItsTotalAcrossContinuationsStopsWhileStillMakingProgress(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(
            tokens: 100,
            content: 'a',
            taskTokens: 9_500,
        ));

        $this->clock->sleep(10);
        $watch = $this->rule->observe($watch, $this->progress(
            tokens: 600,
            content: 'b',
            taskTokens: self::TASK_TOTAL,
        ));

        self::assertSame(BuildStopCause::PerTaskTotal, $this->rule->stopCause($watch));
    }

    public function testARunningCheckKeepsTheBuildGoingPastTheIdleLimit(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(
            tokens: 1_200,
            content: 'a',
        ));

        $this->clock->sleep(self::IDLE_SECONDS - 1);
        $watch = $this->rule->observe($watch, $this->checking(1_300));
        $this->clock->sleep(self::IDLE_SECONDS * 3);
        $watch = $this->rule->observe($watch, $this->checking(1_300));

        self::assertNull($this->rule->stopCause($watch));
    }

    public function testTheIdleTimeStartsAgainWhenARunningCheckEnds(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(
            tokens: 1_200,
            content: 'a',
        ));

        $this->clock->sleep(self::IDLE_SECONDS * 3);
        $watch = $this->rule->observe($watch, $this->checking(1_300));
        $this->clock->sleep(self::IDLE_SECONDS * 3);
        $watch = $this->rule->observe($watch, $this->progress(
            tokens: 1_300,
            content: 'a',
        ));
        $this->clock->sleep(self::IDLE_SECONDS - 1);
        $watch = $this->rule->observe($watch, $this->progress(
            tokens: 1_300,
            content: 'a',
        ));
        $justBeforeTheLimit = $this->rule->stopCause($watch);
        $this->clock->sleep(1);
        $watch = $this->rule->observe($watch, $this->progress(
            tokens: 1_300,
            content: 'a',
        ));

        self::assertNull($justBeforeTheLimit);
        self::assertSame(BuildStopCause::NoProgress, $this->rule->stopCause($watch));
    }

    public function testThePerTaskTotalWinsOverTheCeilingAndNoProgress(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(
            tokens: 1_200,
            content: 'a',
        ));

        $this->clock->sleep(self::IDLE_SECONDS);
        $watch = $this->rule->observe($watch, $this->progress(
            tokens: self::CEILING,
            content: 'a',
            taskTokens: self::TASK_TOTAL,
        ));

        self::assertSame(BuildStopCause::PerTaskTotal, $this->rule->stopCause($watch));
    }

    public function testTheCeilingWinsOverNoProgress(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(
            tokens: 1_200,
            content: 'a',
        ));

        $this->clock->sleep(self::IDLE_SECONDS);
        $watch = $this->rule->observe($watch, $this->progress(
            tokens: self::CEILING,
            content: 'a',
        ));

        self::assertSame(BuildStopCause::RunawayCeiling, $this->rule->stopCause($watch));
    }

    public function testTheCeilingAndThePerTaskTotalStopABuildEvenWhileACheckRuns(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(
            tokens: 1_200,
            content: 'a',
        ));

        $this->clock->sleep(10);
        $ceiling = $this->rule->observe($watch, $this->checking(self::CEILING));
        $total = $this->rule->observe($watch, $this->checking(1_300, self::TASK_TOTAL));

        self::assertSame(BuildStopCause::RunawayCeiling, $this->rule->stopCause($ceiling));
        self::assertSame(BuildStopCause::PerTaskTotal, $this->rule->stopCause($total));
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
        $this->rule = new BuildStopRule($this->clock);
    }

    private function limits(): BuildLimits
    {
        return new BuildLimits(self::ALLOWANCE, self::IDLE_SECONDS, self::CEILING, self::TASK_TOTAL);
    }

    private function progress(int $tokens, string $content = 'a', ?int $taskTokens = null): BuildProgress
    {
        return new BuildProgress($tokens, $taskTokens ?? $tokens, $content, [], false);
    }

    /**
     * @param array<string, string> $failing
     */
    private function failing(int $tokens, array $failing): BuildProgress
    {
        return new BuildProgress($tokens, $tokens, 'a', $failing, false);
    }

    private function checking(int $tokens, ?int $taskTokens = null): BuildProgress
    {
        return new BuildProgress($tokens, $taskTokens ?? $tokens, 'a', [], true);
    }
}
