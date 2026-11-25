<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildLimits;
use Intakt\Core\Build\Domain\BuildProgress;
use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Build\Domain\BuildStopRule;
use Intakt\Core\Build\Domain\BuildWatch;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobOutcome;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobOutcomes;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildVerdict;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Tests\Core\Job\OutputFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(BuildJobOutcomes::class)]
#[CoversClass(BuildJobOutcome::class)]
#[UsesClass(BuildStopRule::class)]
#[UsesClass(BuildWatch::class)]
#[UsesClass(BuildLimits::class)]
#[UsesClass(BuildProgress::class)]
#[UsesClass(JobResult::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobOutput::class)]
final class BuildJobOutcomesTest extends TestCase
{
    private MockClock $clock;

    private BuildStopRule $rule;

    public function testABuildStoppedForNoProgressHandsBackItsBundleAndPullRequestText(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(150));
        $this->clock->sleep(60);

        $outcome = new BuildJobOutcomes()->of($this->cutSession(), $this->rule->stopCause($this->rule->observe($watch, $this->progress(160))));

        $this->assertHandedBack(BuildStopCause::NoProgress, $outcome);
    }

    public function testABuildStoppedAtTheRunawayCeilingHandsBackItsBundleAndPullRequestText(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(150));
        $this->clock->sleep(10);

        $outcome = new BuildJobOutcomes()->of($this->cutSession(), $this->rule->stopCause($this->rule->observe($watch, $this->progress(400, 'b'))));

        $this->assertHandedBack(BuildStopCause::RunawayCeiling, $outcome);
    }

    public function testABuildStoppedAtThePerTaskTotalHandsBackItsBundleAndPullRequestText(): void
    {
        $watch = $this->rule->startWatching($this->limits(), $this->progress(150));
        $this->clock->sleep(10);

        $outcome = new BuildJobOutcomes()->of($this->cutSession(), $this->rule->stopCause($this->rule->observe($watch, $this->progress(200, 'b', 1000))));

        $this->assertHandedBack(BuildStopCause::PerTaskTotal, $outcome);
    }

    public function testAJobWithoutAStopCarriesNoCause(): void
    {
        $outcome = new BuildJobOutcomes()->of($this->cutSession(), null);

        self::assertTrue($outcome->built());
        self::assertNull($outcome->stopCause);
    }

    public function testAStoppedBuildWithoutPullRequestTextKeepsItsBundleAndItsCause(): void
    {
        $outcome = new BuildJobOutcomes()->of(new JobResult(new JobEnd(JobCause::Exited, 0), OutputFiles::of([RunBuildJob::BUNDLE => 'BUNDLE'])), BuildStopCause::NoProgress);

        self::assertSame(BuildVerdict::NoPullRequestText, $outcome->verdict);
        self::assertSame('BUNDLE', $outcome->bundle);
        self::assertSame(BuildStopCause::NoProgress, $outcome->stopCause);
    }

    public function testAStoppedBuildWhoseOutputWasNotClearedHandsNothingBack(): void
    {
        $outcome = new BuildJobOutcomes()->of(new JobResult(new JobEnd(JobCause::Exited, RunBuildJob::OUTPUT_NOT_CLEARED), $this->cutSession()->output), BuildStopCause::RunawayCeiling);

        self::assertSame(BuildVerdict::NoResult, $outcome->verdict);
        self::assertNull($outcome->bundle);
        self::assertSame(BuildStopCause::RunawayCeiling, $outcome->stopCause);
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
        $this->rule = new BuildStopRule($this->clock);
    }

    private function limits(): BuildLimits
    {
        return new BuildLimits(100, 60, 400, 1000);
    }

    private function progress(int $tokens, string $content = 'a', ?int $taskTokens = null): BuildProgress
    {
        return new BuildProgress($tokens, $taskTokens ?? $tokens, $content, [], false);
    }

    // An ordinary exit with the bundle, because the script still bundles the commits and the text of a session it cut.
    private function cutSession(): JobResult
    {
        return new JobResult(new JobEnd(JobCause::Exited, 0), OutputFiles::of([RunBuildJob::BUNDLE => 'BUNDLE', RunBuildJob::PULL_REQUEST_TEXT => "feat: x\n", RunBuildJob::LOG => "log\n"]));
    }

    private function assertHandedBack(BuildStopCause $cause, BuildJobOutcome $outcome): void
    {
        self::assertTrue($outcome->built());
        self::assertSame('BUNDLE', $outcome->bundle);
        self::assertSame("feat: x\n", $outcome->pullRequestText);
        self::assertSame("log\n", $outcome->log);
        self::assertSame($cause, $outcome->stopCause);
    }
}
