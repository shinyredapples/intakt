<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Build\UseCase\BuildTask\BuildRun;
use Intakt\Core\Build\UseCase\BuildTask\EndedStep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuildRun::class)]
#[CoversClass(EndedStep::class)]
final class BuildRunTest extends TestCase
{
    private const string RUN = '0b6f3a52-6c1e-4d0f-9a43-8d2b7e1f5c90';

    #[TestWith([BuildStopCause::NoProgress, 'no_progress'])]
    #[TestWith([BuildStopCause::RunawayCeiling, 'runaway_ceiling'])]
    #[TestWith([BuildStopCause::PerTaskTotal, 'per_task_total'])]
    public function testTheStepPayloadNamesAStopCauseByItsEnumValue(BuildStopCause $cause, string $value): void
    {
        $payload = new BuildRun('alpha', 7, self::RUN)->stepPayload(EndedStep::of(BuildStep::BuildJob, null, $cause));

        self::assertSame('{"project":"alpha","task":"7","run":"'.self::RUN.'","step":"build_job","outcome":"passed","failure":null,"stop_cause":"'.$value.'"}', $payload);
    }

    public function testTheStepPayloadOfAStepThatWasNotStoppedHoldsANullStopCause(): void
    {
        $payload = new BuildRun('alpha', 7, self::RUN)->stepPayload(EndedStep::of(BuildStep::Mirror, 'the mirror failed'));

        self::assertSame('{"project":"alpha","task":"7","run":"'.self::RUN.'","step":"mirror","outcome":"failed","failure":"the mirror failed","stop_cause":null}', $payload);
    }

    public function testAnAskedStepKeepsItsOutcomeWithoutAFailureOrStopCause(): void
    {
        $payload = new BuildRun('alpha', 7, self::RUN)->stepPayload(new EndedStep(BuildStep::BuildJob, BuildStepOutcome::Asked, null, null));

        self::assertSame('{"project":"alpha","task":"7","run":"'.self::RUN.'","step":"build_job","outcome":"asked","failure":null,"stop_cause":null}', $payload);
    }
}
