<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Build\Domain\BuildUsage;
use Intakt\Core\Build\Domain\SessionUsage;
use Intakt\Core\Build\Domain\UsageReading;
use Intakt\Core\Build\UseCase\BuildTask\BuildRun;
use Intakt\Core\Build\UseCase\BuildTask\EndedStep;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobOutcome;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildVerdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuildRun::class)]
#[CoversClass(EndedStep::class)]
#[UsesClass(BuildJobOutcome::class)]
#[UsesClass(BuildVerdict::class)]
#[UsesClass(SessionUsage::class)]
#[UsesClass(BuildUsage::class)]
#[UsesClass(UsageReading::class)]
final class BuildRunTest extends TestCase
{
    private const string RUN = '0b6f3a52-6c1e-4d0f-9a43-8d2b7e1f5c90';

    #[TestWith([BuildStopCause::NoProgress, 'no_progress'])]
    #[TestWith([BuildStopCause::RunawayCeiling, 'runaway_ceiling'])]
    #[TestWith([BuildStopCause::PerTaskTotal, 'per_task_total'])]
    public function testTheStepPayloadNamesAStopCauseByItsEnumValue(BuildStopCause $cause, string $value): void
    {
        $payload = new BuildRun('alpha', 7, self::RUN)->stepPayload(EndedStep::of(BuildStep::BuildJob, null, $cause));

        self::assertSame('{"project":"alpha","task":"7","run":"'.self::RUN.'","step":"build_job","outcome":"passed","failure":null,"stop_cause":"'.$value.'","usage":null}', $payload);
    }

    public function testTheStepPayloadOfAStepThatWasNotStoppedHoldsANullStopCause(): void
    {
        $payload = new BuildRun('alpha', 7, self::RUN)->stepPayload(EndedStep::of(BuildStep::Mirror, 'the mirror failed'));

        self::assertSame('{"project":"alpha","task":"7","run":"'.self::RUN.'","step":"mirror","outcome":"failed","failure":"the mirror failed","stop_cause":null,"usage":null}', $payload);
    }

    public function testAnAskedStepKeepsItsOutcomeWithoutAFailureOrStopCause(): void
    {
        $payload = new BuildRun('alpha', 7, self::RUN)->stepPayload(new EndedStep(BuildStep::BuildJob, BuildStepOutcome::Asked, null, null));

        self::assertSame('{"project":"alpha","task":"7","run":"'.self::RUN.'","step":"build_job","outcome":"asked","failure":null,"stop_cause":null,"usage":null}', $payload);
    }

    public function testTheStepPayloadOfABuildJobNamesItsStopCauseAndEachCountOfItsUsage(): void
    {
        $job = new BuildJobOutcome(
            BuildVerdict::NoCommits,
            stopCause: BuildStopCause::NoProgress,
            usage: SessionUsage::read(new BuildUsage(1, 2, 3, 4)),
        );

        $payload = new BuildRun('alpha', 7, self::RUN)->stepPayload(EndedStep::ofBuildJob(BuildVerdict::NoCommits->reason(), $job));

        self::assertSame('{"project":"alpha","task":"7","run":"'.self::RUN.'","step":"build_job","outcome":"failed","failure":"'.BuildVerdict::NoCommits->reason().'","stop_cause":"no_progress","usage":{"reading":"read","input_tokens":1,"output_tokens":2,"cache_read_input_tokens":3,"cache_creation_input_tokens":4}}', $payload);
    }

    public function testTheStepPayloadOfABuildJobWhoseLogWasRefusedNamesWhy(): void
    {
        $job = new BuildJobOutcome(BuildVerdict::Built, usage: SessionUsage::withoutUsage(UsageReading::TooLarge));

        $payload = new BuildRun('alpha', 7, self::RUN)->stepPayload(EndedStep::ofBuildJob(null, $job));

        self::assertStringEndsWith('"outcome":"passed","failure":null,"stop_cause":null,"usage":{"reading":"too_large","input_tokens":null,"output_tokens":null,"cache_read_input_tokens":null,"cache_creation_input_tokens":null}}', $payload);
    }
}
