<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Tests\Support\KernelTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RunBuildJob::class)]
final class RefusedBuildLimitsConfigTest extends KernelTestCase
{
    public function testABuildLimitThatIsNotAWholeNumberInTheConfigurationIsRefusedWhenTheBuildJobIsBuilt(): void
    {
        self::bootKernel();

        $this->expectExceptionObject(new InvalidArgumentException('The build\'s idle time must be a whole number, not bool.'));

        self::getContainer()->get(RunBuildJob::class);
    }

    protected static function getKernelClass(): string
    {
        return RefusedBuildLimitsKernel::class;
    }
}
