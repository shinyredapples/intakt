<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildBrief;
use Intakt\Core\Build\Contract\Decisions;
use Intakt\Core\Build\Domain\BuilderPrompt;
use Intakt\Core\Build\Domain\BuildLimits;
use Intakt\Core\Build\Domain\BuildProgress;
use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Build\Domain\BuildStopRule;
use Intakt\Core\Build\Domain\BuildWatch;
use Intakt\Core\Build\Domain\GatesAtOnce;
use Intakt\Core\Build\Domain\GatesByDefault;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJob;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobOutcome;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildLogReading;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildSessionWatch;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildVerdict;
use Intakt\Core\Build\UseCase\RunBuildJob\RecordedBuildStops;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Job\Contract\JobWatch;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\Job\OutputFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

// The job runner here plays the container's wait: it moves the mock clock one interval and asks the watch, in the order each test scripts, so no test repeats a run or waits.
#[CoversClass(BuildSessionWatch::class)]
#[CoversClass(RunBuildJob::class)]
#[UsesClass(BuildLogReading::class)]
#[UsesClass(RecordedBuildStops::class)]
#[UsesClass(BuildJobOutcome::class)]
#[UsesClass(BuildStopRule::class)]
#[UsesClass(BuildWatch::class)]
#[UsesClass(BuildProgress::class)]
#[UsesClass(BuildLimits::class)]
#[UsesClass(BuildJob::class)]
#[UsesClass(BuildBrief::class)]
#[UsesClass(BuilderPrompt::class)]
#[UsesClass(GatesAtOnce::class)]
#[UsesClass(GatesByDefault::class)]
#[UsesClass(Fence::class)]
#[UsesClass(PromptCut::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(PhpGate::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobInput::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobResult::class)]
final class WatchedBuildJobTest extends TestCase
{
    private const int TICK = BuildSessionWatch::INTERVAL_SECONDS;

    private string $folder = '';

    private MockClock $clock;

    /** @var list<bool> */
    private array $answers = [];

    public function testABuildIdleOverItsAllowanceIsStoppedForNoProgressAndKeepsItsCommits(): void
    {
        $outcome = $this->runWatched(new BuildLimits(100, 60, 1000, 1000), [
            BuildLogLines::usage('m1', 150),
            BuildLogLines::usage('m1', 150),
            BuildLogLines::usage('m1', 150),
        ]);

        self::assertSame([false, true], $this->answers);
        self::assertSame([BuildVerdict::Built, 'BUNDLE', BuildStopCause::NoProgress], [$outcome->verdict, $outcome->bundle, $outcome->stopCause]);
    }

    public function testABuildReachingTheRunawayCeilingWhileMakingProgressIsStoppedAndKeepsItsCommits(): void
    {
        $outcome = $this->runWatched(new BuildLimits(100, 60, 500, 10000), [
            BuildLogLines::usage('m1', 150).BuildLogLines::edit('c1', 'a', 'b'),
            BuildLogLines::usage('m1', 150).BuildLogLines::edit('c1', 'a', 'b').BuildLogLines::usage('m2', 450).BuildLogLines::edit('c2', 'b', 'c'),
            BuildLogLines::usage('m3', 1),
        ]);

        self::assertSame([false, true], $this->answers);
        self::assertSame([BuildVerdict::Built, 'BUNDLE', BuildStopCause::RunawayCeiling], [$outcome->verdict, $outcome->bundle, $outcome->stopCause]);
    }

    public function testABuildWhoseTaskReachedThePerTaskTotalIsStoppedAndKeepsItsCommits(): void
    {
        $outcome = $this->runWatched(new BuildLimits(100, 60, 500, 300), [
            BuildLogLines::usage('m1', 50),
            BuildLogLines::usage('m1', 350).BuildLogLines::edit('c1', 'a', 'b'),
            BuildLogLines::usage('m3', 1),
        ]);

        self::assertSame([false, true], $this->answers);
        self::assertSame([BuildVerdict::Built, 'BUNDLE', BuildStopCause::PerTaskTotal], [$outcome->verdict, $outcome->bundle, $outcome->stopCause]);
    }

    public function testABuildOverItsAllowanceThatKeepsMakingProgressIsNotStopped(): void
    {
        $log = BuildLogLines::usage('m0', 150);
        $logs = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $letter) {
            $log .= BuildLogLines::edit('c'.$letter, $letter, $letter.$letter);
            $logs[] = $log;
        }

        $outcome = $this->runWatched(new BuildLimits(100, 60, 100000, 100000), $logs);

        self::assertSame([false, false, false, false, false], $this->answers);
        self::assertNull($outcome->stopCause);
    }

    public function testALowerTokenFigureInTheLogNeverLowersWhatTheWatchSaw(): void
    {
        $outcome = $this->runWatched(new BuildLimits(100, 60, 1000, 10000), [
            BuildLogLines::usage('m1', 900).BuildLogLines::edit('c1', 'a', 'b'),
            BuildLogLines::usage('m1', 20).BuildLogLines::edit('c1', 'a', 'b'),
            BuildLogLines::usage('m1', 20).BuildLogLines::edit('c1', 'a', 'b'),
        ]);

        self::assertSame([false, false, true], $this->answers);
        self::assertSame(BuildStopCause::NoProgress, $outcome->stopCause);
    }

    public function testARunningCheckCountsAsProgressForABuildOverItsAllowance(): void
    {
        $running = BuildLogLines::usage('m1', 150).BuildLogLines::command('c1', 'vendor/bin/phpunit');

        $outcome = $this->runWatched(new BuildLimits(100, 60, 1000, 1000), [$running, $running, $running, $running]);

        self::assertSame([false, false, false, false], $this->answers);
        self::assertNull($outcome->stopCause);
    }

    public function testABuildUnderItsAllowanceIsNotStoppedForNoProgress(): void
    {
        $outcome = $this->runWatched(new BuildLimits(1000, 60, 2000, 5000), [
            BuildLogLines::usage('m1', 10),
            BuildLogLines::usage('m1', 10),
            BuildLogLines::usage('m1', 10),
        ]);

        self::assertSame([false, false, false], $this->answers);
        self::assertNull($outcome->stopCause);
    }

    public function testTheWatchAsksAtTheIntervalTheJobWaitsIn(): void
    {
        self::assertSame(self::TICK, new BuildSessionWatch($this->job(), new BuildStopRule($this->clock), new RecordedBuildStops(), new BuildLimits(1, 1, 1, 1))->intervalSeconds());
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-01-01 00:00:00');
        $this->folder = sys_get_temp_dir().'/intakt-watched-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->folder.'/clone');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    /**
     * @param list<string> $logs
     */
    private function runWatched(BuildLimits $limits, array $logs): BuildJobOutcome
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec, ?JobWatch $watch) use ($logs): JobResult {
            self::assertInstanceOf(JobWatch::class, $watch);
            foreach ($logs as $log) {
                $this->clock->sleep($watch->intervalSeconds());
                $this->answers[] = $watch->endsSession(OutputFiles::of(['build.log' => $log]));
                if (end($this->answers)) {
                    break;
                }
            }

            return new JobResult(new JobEnd(JobCause::Exited, 0), OutputFiles::of(['handback.bundle' => 'BUNDLE', 'pull-request.md' => "feat: x\n"]));
        });
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturn(new ResolvedModel(Provider::ClaudeCode, 'claude-sonnet-5-5'));
        $builds = new RunBuildJob(
            $tiers,
            new BuilderPrompt(new Fence(), new PromptCut(new Fence())),
            $runner,
            new PhpGate(),
            new GatesAtOnce(12, 1),
            1500,
            new Filesystem(),
            __DIR__.'/../../../../../docker/job/build.sh',
            $this->folder.'/prompts',
            new RecordedBuildStops(),
            new BuildStopRule($this->clock),
            $limits,
        );

        return $builds->run($this->job());
    }

    private function job(): BuildJob
    {
        return new BuildJob('demo', $this->folder.'/clone', 'task', new BuildBrief('task text', new Decisions(), null), Tier::Standard, 'intakt-job-demo:1');
    }
}
