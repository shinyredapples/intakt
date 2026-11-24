<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class SuiteBuildDirectoryTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    public function testAKernelOfTheSuiteCompilesIntoItsCacheDirectoryWhenTheCallerSetsABuildDirectory(): void
    {
        [$build, $cache] = $this->directoriesSeenAfterTheBootstrap();

        self::assertSame($cache, $build);
    }

    public function testAProcessATestStartsInheritsNoBuildDirectory(): void
    {
        [, , $inherited] = $this->directoriesSeenAfterTheBootstrap();

        self::assertFalse($inherited);
    }

    /**
     * @return array{mixed, mixed, mixed}
     */
    private function directoriesSeenAfterTheBootstrap(): array
    {
        $scratch = sys_get_temp_dir().'/suite-build-directory';
        $read = 'require "tests/bootstrap.php"; $kernel = new Intakt\Kernel("test", false); echo json_encode([$kernel->getBuildDir(), $kernel->getCacheDir(), getenv("APP_BUILD_DIR")]);';
        $process = new Process([PHP_BINARY, '-r', $read], self::ROOT, ['APP_ENV' => 'test', 'APP_DEBUG' => '0', 'APP_CACHE_DIR' => $scratch.'/cache', 'APP_BUILD_DIR' => $scratch.'/inherited-build']);
        $process->mustRun();

        $seen = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($seen);
        self::assertCount(3, $seen);

        return [$seen[0], $seen[1], $seen[2]];
    }
}
