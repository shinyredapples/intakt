<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuilderPrompt;
use Intakt\Core\Build\Domain\BuildLimits;
use Intakt\Core\Build\Domain\GatesAtOnce;
use Intakt\Core\Build\Domain\GatesByDefault;
use Intakt\Core\Build\UseCase\RunBuildJob\RecordedBuildStops;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(RunBuildJob::class)]
#[UsesClass(BuildLimits::class)]
#[UsesClass(BuilderPrompt::class)]
#[UsesClass(GatesAtOnce::class)]
#[UsesClass(GatesByDefault::class)]
#[UsesClass(RecordedBuildStops::class)]
#[UsesClass(Fence::class)]
#[UsesClass(PromptCut::class)]
#[UsesClass(PhpGate::class)]
final class BuildLimitsConfigTest extends KernelTestCase
{
    public function testTheBuildJobTakesItsLimitsFromTheInstallSettings(): void
    {
        self::bootKernel();

        $job = self::getContainer()->get(RunBuildJob::class);

        self::assertInstanceOf(RunBuildJob::class, $job);
        self::assertEquals(new BuildLimits(BuildLimitsKernel::ALLOWANCE_TOKENS, BuildLimitsKernel::IDLE_SECONDS, BuildLimitsKernel::CEILING_TOKENS, BuildLimitsKernel::TASK_TOTAL_TOKENS), $job->limits);
    }

    protected static function getKernelClass(): string
    {
        return BuildLimitsKernel::class;
    }
}
