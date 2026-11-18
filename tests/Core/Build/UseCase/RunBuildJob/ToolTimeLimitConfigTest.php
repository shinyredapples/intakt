<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;

#[CoversClass(RunBuildJob::class)]
final class ToolTimeLimitConfigTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return ToolTimeLimitKernel::class;
    }

    public function testTheBuildJobTakesItsToolTimeLimitFromTheInstallSetting(): void
    {
        self::bootKernel();
        $job = self::getContainer()->get(RunBuildJob::class);

        self::assertInstanceOf(RunBuildJob::class, $job);
        self::assertSame(ToolTimeLimitKernel::LIMIT, new ReflectionProperty($job, 'toolTimeLimitSeconds')->getValue($job));
    }
}
