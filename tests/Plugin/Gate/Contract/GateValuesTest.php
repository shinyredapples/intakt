<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Gate\Contract;

use Intakt\Plugin\Gate\Contract\CheckSteps;
use Intakt\Plugin\Gate\Contract\LockFiles;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckSteps::class)]
#[CoversClass(LockFiles::class)]
final class GateValuesTest extends TestCase
{
    public function testCheckStepsKeepTheOrderTheyRunIn(): void
    {
        self::assertSame(['composer install', 'bin/check --fast'], [...new CheckSteps('composer install', 'bin/check --fast')]);
    }

    public function testACheckWithoutAStepIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CheckSteps();
    }

    public function testLockFilesKeepTheOrderTheyAreNamedIn(): void
    {
        self::assertSame(['composer.json', 'composer.lock'], [...new LockFiles('composer.json', 'composer.lock')]);
    }

    public function testADependencyStepWithoutALockFileIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LockFiles();
    }
}
