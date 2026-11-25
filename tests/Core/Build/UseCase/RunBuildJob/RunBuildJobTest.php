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
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\GatesAtOnce;
use Intakt\Core\Build\Domain\GatesByDefault;
use Intakt\Core\Build\Domain\QuestionRefused;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildCloneIsNoFolder;
use Intakt\Core\Build\UseCase\RunBuildJob\BuilderNotOnClaudeCode;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJob;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobOutcome;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobOutcomes;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildVerdict;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobOutputRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\Job\OutputFiles;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(RunBuildJob::class)]
#[CoversClass(BuildJobOutcome::class)]
#[CoversClass(BuildVerdict::class)]
#[CoversClass(BuilderNotOnClaudeCode::class)]
#[CoversClass(BuildCloneIsNoFolder::class)]
#[CoversClass(BuildJobOutcomes::class)]
#[UsesClass(BuildLimits::class)]
#[UsesClass(BuildJob::class)]
#[UsesClass(BuildBrief::class)]
#[UsesClass(BuilderPrompt::class)]
#[UsesClass(BuildQuestion::class)]
#[UsesClass(QuestionRefused::class)]
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
#[UsesClass(JobResult::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobOutput::class)]
final class RunBuildJobTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../../../docker/job/build.sh';

    private string $folder = '';

    private ?JobSpec $ran = null;

    private string $promptSeen = '';

    private GatesAtOnce $gatesAtOnce;

    private mixed $toolTimeLimit = 1500;

    private ?BuildLimits $limits = null;

    public function testTheBuildJobKeepsTheLimitsItIsGiven(): void
    {
        $this->limits = new BuildLimits(1, 1, 1, 1);

        self::assertEquals(new BuildLimits(1, 1, 1, 1), $this->runBuilds(OutputFiles::of([]))->limits);
    }

    public function testAJobThatRanToItsEndCarriesNoStopCause(): void
    {
        $outcome = $this->runBuilds(OutputFiles::of(['handback.bundle' => 'BUNDLE', 'pull-request.md' => "feat: x\n"]))->run($this->job());

        self::assertNull($outcome->stopCause);
    }

    public function testAJobThatLeavesABundleAndTheTextIsBuilt(): void
    {
        $outcome = $this->runBuilds(OutputFiles::of(['handback.bundle' => 'BUNDLE', 'pull-request.md' => "feat: x\n", 'build.log' => "log\n"]))->run($this->job());

        self::assertTrue($outcome->built());
        self::assertSame('BUNDLE', $outcome->bundle);
        self::assertSame("feat: x\n", $outcome->pullRequestText);
        self::assertSame("log\n", $outcome->log);
    }

    public function testTheTiersModelAndTheBranchReachTheBuildScript(): void
    {
        (void) $this->runBuilds(OutputFiles::of([]), new ResolvedModel(Provider::ClaudeCode, 'claude-opus-5-5'))->run($this->job(Tier::Strong));

        self::assertNotNull($this->ran);
        self::assertSame(
            ['/bin/sh', '-c', (string) file_get_contents(self::SCRIPT), 'intakt-build', RunBuildJob::CLONE, RunBuildJob::PROMPT, 'task', 'claude-opus-5-5', '100', '3000', '', '1500'],
            [...$this->ran->command],
        );
    }

    #[TestWith([1800])]
    #[TestWith([1])]
    #[TestWith([2999])]
    public function testTheBuilderSessionIsStartedWithTheToolTimeLimitFromConfiguration(int $seconds): void
    {
        $this->toolTimeLimit = $seconds;

        (void) $this->runBuilds(OutputFiles::of([]))->run($this->job());

        self::assertNotNull($this->ran);
        self::assertSame((string) $seconds, [...$this->ran->command][11]);
    }

    #[TestWith([3000])]
    #[TestWith([3001])]
    public function testAToolTimeLimitNotBelowTheSessionsAllowanceIsRefused(int $seconds): void
    {
        $this->toolTimeLimit = $seconds;

        $this->expectExceptionObject(new InvalidArgumentException(sprintf('The builder\'s tool time limit must be at least 1 second and below the session\'s allowance of 3000 seconds, not %d.', $seconds)));

        $this->runBuilds(OutputFiles::of([]));
    }

    #[TestWith([true])]
    #[TestWith(['1500'])]
    #[TestWith([1500.0])]
    public function testAToolTimeLimitThatIsNotAWholeNumberIsRefusedAsGiven(mixed $given): void
    {
        $this->toolTimeLimit = $given;

        $this->expectExceptionMessageMatches('/tool time limit must be a whole number of seconds, not /');

        $this->runBuilds(OutputFiles::of([]));
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    public function testAToolTimeLimitBelowOneSecondIsRefused(int $seconds): void
    {
        $this->toolTimeLimit = $seconds;

        $this->expectExceptionMessageMatches('/must be at least 1 second .*, not '.$seconds.'\.$/');

        $this->runBuilds(OutputFiles::of([]));
    }

    #[TestWith([12, 2])]
    #[TestWith([12, 3])]
    #[TestWith([2, 3])]
    public function testABuildJobHandsItsBuilderNoGateCountBecauseSeveralSlotsWouldRaiseTheOneGateItsLimitsAllow(int $processors, int $slots): void
    {
        $this->gatesAtOnce = new GatesAtOnce($processors, $slots);

        (void) $this->runBuilds(OutputFiles::of([]))->run($this->job());

        self::assertNotNull($this->ran);
        self::assertSame('', [...$this->ran->command][10]);
        self::assertSame(1, new GatesByDefault($processors, $this->ran->limits->cpus, $this->ran->limits->memoryBytes)->count);
    }

    public function testTheJobsTimeLimitLeavesRoomToBundleAfterTheAllowance(): void
    {
        (void) $this->runBuilds(OutputFiles::of([]))->run($this->job());

        self::assertNotNull($this->ran);
        self::assertGreaterThan((int) [...$this->ran->command][9], $this->ran->limits->seconds);
    }

    public function testTheBuildClonedAndThePromptAreTheJobsOnlyInputs(): void
    {
        (void) $this->runBuilds(OutputFiles::of([]))->run($this->job());

        self::assertNotNull($this->ran);
        self::assertSame([$this->folder.'/clone', RunBuildJob::CLONE], [$this->ran->inputs[0]->hostPath, $this->ran->inputs[0]->containerPath]);
        self::assertSame(RunBuildJob::PROMPT, $this->ran->inputs[1]->containerPath);
        self::assertCount(2, $this->ran->inputs);
    }

    public function testThePromptFileHoldsTheFencedTaskWhileTheJobRunsAndIsRemovedAfterwards(): void
    {
        (void) $this->runBuilds(OutputFiles::of([]))->run($this->job());

        self::assertNotNull($this->ran);
        self::assertStringContainsString(new Fence()->wrap(UntrustedSource::Task, 'PLANT-task')->rendered(), $this->promptSeen);
        self::assertFileDoesNotExist($this->ran->inputs[1]->hostPath);
    }

    public function testThePromptFileIsRemovedWhenTheJobFails(): void
    {
        $runner = self::createStub(JobRunner::class);
        $prompt = null;
        $runner->method('run')->willReturnCallback(static function (JobSpec $spec) use (&$prompt): never {
            $prompt = $spec->inputs[1]->hostPath;

            throw new RuntimeException('engine gone');
        });

        try {
            (void) $this->useCase($runner, new ResolvedModel(Provider::ClaudeCode, 'claude-sonnet-5-5'))->run($this->job());
        } catch (RuntimeException) {
        }

        self::assertIsString($prompt);
        self::assertFileDoesNotExist($prompt);
    }

    public function testTheJobMayReachTheVendorsApiAndTheGatesRegistriesOnly(): void
    {
        (void) $this->runBuilds(OutputFiles::of([]))->run($this->job());

        self::assertNotNull($this->ran);
        self::assertSame(['api.anthropic.com', ...new PhpGate()->registryHosts()], [...$this->ran->egress->hosts]);
    }

    public function testTheJobIdNamesTheProjectAndBranchSoASecondBuildOfThemIsRefusedWhileOneRuns(): void
    {
        (void) $this->runBuilds(OutputFiles::of([]))->run($this->job());
        $first = $this->ran?->id->value;
        (void) $this->runBuilds(OutputFiles::of([]))->run(new BuildJob('demo', $this->folder.'/clone', 'task', new BuildBrief('again', new Decisions(), null), Tier::Light, 'img'));
        $second = $this->ran?->id->value;
        (void) $this->runBuilds(OutputFiles::of([]))->run(new BuildJob('demot', $this->folder.'/clone', 'ask', new BuildBrief('x', new Decisions(), null), Tier::Light, 'img'));

        self::assertSame($first, $second);
        self::assertNotSame($first, $this->ran?->id->value);
        self::assertStringStartsWith('build-', (string) $first);
    }

    public function testATierOnAnotherProviderIsRefusedBeforeAnyJobStarts(): void
    {
        $runner = $this->createMock(JobRunner::class);
        $runner->expects(self::never())->method('run');

        $this->expectExceptionObject(new BuilderNotOnClaudeCode('demo', Tier::Standard, Provider::Anthropic));

        (void) $this->useCase($runner, new ResolvedModel(Provider::Anthropic, 'claude-sonnet-5-5'))->run($this->job());
    }

    public function testACloneThatIsNoFolderIsRefused(): void
    {
        $this->expectExceptionObject(BuildCloneIsNoFolder::at($this->folder.'/missing'));

        (void) $this->runBuilds(OutputFiles::of([]))->run(new BuildJob('demo', $this->folder.'/missing', 'task', new BuildBrief('x', new Decisions(), null), Tier::Standard, 'img'));
    }

    public function testAJobWithoutABundleMadeNoCommits(): void
    {
        $outcome = $this->runBuilds(OutputFiles::of(['pull-request.md' => "feat: x\n"]))->run($this->job());

        self::assertSame(BuildVerdict::NoCommits, $outcome->verdict);
        self::assertNull($outcome->bundle);
    }

    public function testAJobThatLeavesAValidQuestionEndsAsAskedCarryingTheQuestion(): void
    {
        $question = '{"question":"Which store?","choices":["SQLite","Postgres"],"recommendation":"SQLite"}';

        $outcome = $this->runBuilds(OutputFiles::of(['question.json' => $question, 'handback.bundle' => 'BUNDLE', 'pull-request.md' => "feat: x\n"]))->run($this->job());

        self::assertSame(BuildVerdict::Asked, $outcome->verdict);
        self::assertSame(['Which store?', ['SQLite', 'Postgres'], 'SQLite'], [$outcome->question?->question, $outcome->question?->choices, $outcome->question?->recommendation]);
        self::assertNull($outcome->bundle);
        self::assertFalse($outcome->built());
    }

    public function testAJobThatLeavesARefusedQuestionEndsAsRefusedWithTheReasonInItsLog(): void
    {
        $question = '{"question":"Which store?","choices":["SQLite"],"recommendation":"Postgres"}';

        $outcome = $this->runBuilds(OutputFiles::of(['question.json' => $question, 'build.log' => "log\n"]))->run($this->job());

        self::assertSame(BuildVerdict::QuestionRefused, $outcome->verdict);
        self::assertNull($outcome->question);
        self::assertSame("log\nquestion refused: the recommendation is not one of the choices\n", $outcome->log);
    }

    public function testAJobWithoutThePullRequestTextIsNotBuilt(): void
    {
        $outcome = $this->runBuilds(OutputFiles::of(['handback.bundle' => 'BUNDLE']))->run($this->job());

        self::assertSame(BuildVerdict::NoPullRequestText, $outcome->verdict);
        self::assertFalse($outcome->built());
    }

    public function testAJobTheEngineStoppedIsNotBuiltWhateverItsOutputSays(): void
    {
        $output = OutputFiles::of(['handback.bundle' => 'BUNDLE', 'pull-request.md' => "feat: x\n", 'build.log' => "cut\n"]);

        $killed = $this->runBuilds($output, end: new JobEnd(JobCause::MemoryKilled, 137))->run($this->job());
        $timedOut = $this->runBuilds($output, end: new JobEnd(JobCause::TimedOut, 137))->run($this->job());

        self::assertSame([BuildVerdict::MemoryKilled, null, "cut\n"], [$killed->verdict, $killed->bundle, $killed->log]);
        self::assertSame(BuildVerdict::TimedOut, $timedOut->verdict);
    }

    public function testAJobWhoseScriptCouldNotClearTheOutputIsNoResultWhateverItsOutputSays(): void
    {
        $output = OutputFiles::of(['handback.bundle' => 'PLANTED', 'pull-request.md' => "planted\n", 'build.log' => "log\n"]);

        $outcome = $this->runBuilds($output, end: new JobEnd(JobCause::Exited, RunBuildJob::OUTPUT_NOT_CLEARED))->run($this->job());

        self::assertSame([BuildVerdict::NoResult, null, null, "log\n"], [$outcome->verdict, $outcome->bundle, $outcome->pullRequestText, $outcome->log]);
    }

    public function testAJobThatExitedWithAnotherFailingCodeIsStillReadFromItsOutput(): void
    {
        $output = OutputFiles::of(['handback.bundle' => 'BUNDLE', 'pull-request.md' => "feat: x\n"]);

        $outcome = $this->runBuilds($output, end: new JobEnd(JobCause::Exited, 1))->run($this->job());

        self::assertSame(BuildVerdict::Built, $outcome->verdict);
    }

    public function testAnOutputTheRunnerRefusesIsNoResult(): void
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willThrowException(JobOutputRefused::tooLarge('handback.bundle', 16));

        $outcome = $this->useCase($runner, new ResolvedModel(Provider::ClaudeCode, 'claude-sonnet-5-5'))->run($this->job());

        self::assertSame(BuildVerdict::NoResult, $outcome->verdict);
    }

    protected function setUp(): void
    {
        $this->gatesAtOnce = new GatesAtOnce(12, 1);
        $this->folder = sys_get_temp_dir().'/intakt-build-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->folder.'/clone');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    private function runBuilds(JobOutput $output, ?ResolvedModel $model = null, JobEnd $end = new JobEnd(JobCause::Exited, 0)): RunBuildJob
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use ($output, $end): JobResult {
            $this->ran = $spec;
            $this->promptSeen = (string) file_get_contents($spec->inputs[1]->hostPath);

            return new JobResult($end, $output);
        });

        return $this->useCase($runner, $model ?? new ResolvedModel(Provider::ClaudeCode, 'claude-sonnet-5-5'));
    }

    private function job(Tier $tier = Tier::Standard): BuildJob
    {
        return new BuildJob('demo', $this->folder.'/clone', 'task', new BuildBrief('PLANT-task', new Decisions(), null), $tier, 'intakt-job-demo:1');
    }

    private function useCase(JobRunner $runner, ResolvedModel $model): RunBuildJob
    {
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturn($model);

        return new RunBuildJob($tiers, new BuilderPrompt(new Fence(), new PromptCut(new Fence())), $runner, new BuildJobOutcomes(), new PhpGate(), $this->gatesAtOnce, $this->toolTimeLimit, $this->limits ?? new BuildLimits(2_000_000, 900, 6_000_000, 12_000_000), new Filesystem(), self::SCRIPT, $this->folder.'/prompts');
    }
}
