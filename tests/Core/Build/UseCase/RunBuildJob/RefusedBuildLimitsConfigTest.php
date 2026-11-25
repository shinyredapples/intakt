<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Infrastructure\BuildLimitsPass;
use Intakt\Tests\Support\KernelTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(BuildLimitsPass::class)]
final class RefusedBuildLimitsConfigTest extends KernelTestCase
{
    public function testABuildLimitThatIsNotAWholeNumberInTheConfigurationIsRefusedWhenTheContainerIsBuilt(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('The build\'s idle time must be a whole number, not bool.'));

        self::bootKernel();
    }

    protected static function getKernelClass(): string
    {
        return RefusedBuildLimitsKernel::class;
    }
}
