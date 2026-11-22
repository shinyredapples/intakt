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
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\GatesAtOnce;
use Intakt\Core\Build\Domain\GatesByDefault;
use Intakt\Core\Build\Domain\QuestionRefused;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJob;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\Build\ScriptedJobRunner;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(RunBuildJob::class)]
#[UsesClass(BuildBrief::class)]
#[UsesClass(BuildJob::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(JobResult::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobOutput::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(BuilderPrompt::class)]
#[UsesClass(BuildQuestion::class)]
#[UsesClass(GatesAtOnce::class)]
#[UsesClass(GatesByDefault::class)]
#[UsesClass(QuestionRefused::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobInput::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(Fence::class)]
#[UsesClass(PromptCut::class)]
#[UsesClass(PhpGate::class)]
final class ToolTimeLimitConfigTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return ToolTimeLimitKernel::class;
    }

    public function testTheBuildJobTakesItsToolTimeLimitFromTheInstallSetting(): void
    {
        self::bootKernel();
        $ran = null;
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(static function (JobSpec $spec) use (&$ran): JobResult {
            $ran = $spec;

            return new JobResult(new JobEnd(JobCause::Exited, 0), new JobOutput([]));
        });
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturn(new ResolvedModel(Provider::ClaudeCode, 'claude-sonnet-5-5'));
        self::getContainer()->set(ScriptedJobRunner::class, $runner);
        self::getContainer()->set(TierResolver::class, $tiers);
        $folder = sys_get_temp_dir().'/'.uniqid('intakt-tool-limit-', true);
        new Filesystem()->mkdir($folder.'/clone');

        try {
            $job = self::getContainer()->get(RunBuildJob::class);
            self::assertInstanceOf(RunBuildJob::class, $job);
            (void) $job->run(new BuildJob('demo', $folder.'/clone', 'task', new BuildBrief('PLANT-task', new Decisions(), null), Tier::Standard, 'intakt-job-demo:1'));
        } finally {
            new Filesystem()->remove($folder);
        }

        self::assertInstanceOf(JobSpec::class, $ran);
        self::assertSame((string) ToolTimeLimitKernel::LIMIT, $ran->command[11]);
    }
}
