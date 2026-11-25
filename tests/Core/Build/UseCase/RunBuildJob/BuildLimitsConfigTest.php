<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildLimits;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(RunBuildJob::class)]
#[UsesClass(BuildLimits::class)]
final class BuildLimitsConfigTest extends KernelTestCase
{
    public function testTheShippedBuildLimitsReachTheBuildJob(): void
    {
        self::bootKernel();

        $job = self::getContainer()->get(RunBuildJob::class);

        self::assertInstanceOf(RunBuildJob::class, $job);
        self::assertEquals(new BuildLimits(2_000_000, 900, 6_000_000, 12_000_000), $job->limits);
    }
}
