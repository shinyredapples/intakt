<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Contract\BuildServices;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;

#[CoversClass(RunBuildJob::class)]
final class ToolTimeLimitConfigTest extends KernelTestCase
{
    public function testTheBuildJobTakesItsToolTimeLimitFromTheInstallSetting(): void
    {
        self::bootKernel();
        $setting = self::getContainer()->getParameter(BuildServices::TOOL_TIME_LIMIT);
        $job = self::getContainer()->get(RunBuildJob::class);

        self::assertInstanceOf(RunBuildJob::class, $job);
        self::assertSame($setting, new ReflectionProperty($job, 'toolTimeLimitSeconds')->getValue($job));
    }
}
