<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job\Contract;

use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobKeptFolder;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobOutputFile;
use Intakt\Core\Job\Contract\JobRefused;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobId::class)]
#[CoversClass(JobInput::class)]
#[CoversClass(JobKeptFolder::class)]
#[CoversClass(JobLimits::class)]
#[CoversClass(JobOutput::class)]
#[CoversClass(JobRefused::class)]
final class JobSpecTest extends TestCase
{
    public function testAnIdOfSixtyThreePlainCharactersIsAccepted(): void
    {
        $id = str_repeat('a', 63);

        self::assertSame($id, new JobId($id)->value);
        self::assertTrue(JobId::isValid('build-12.review_3'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedIds(): iterable
    {
        yield 'empty' => [''];
        yield 'sixty-four characters' => [str_repeat('a', 64)];
        yield 'uppercase' => ['Build-1'];
        yield 'leading dot' => ['.build'];
        yield 'leading dash' => ['-build'];
        yield 'a slash' => ['build/../1'];
        yield 'a trailing newline' => ["build-1\n"];
    }

    #[DataProvider('refusedIds')]
    public function testAnIdThatCouldNotNameAPathIsRefused(string $id): void
    {
        self::assertFalse(JobId::isValid($id));
        $this->expectExceptionObject(JobRefused::forId($id));

        new JobId($id);
    }

    public function testAnInputBesideTheWorkAreaIsAccepted(): void
    {
        $input = new JobInput('/srv/mirror', '/workspace');

        self::assertSame('/workspace', $input->containerPath);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedInputs(): iterable
    {
        yield 'a relative host path' => ['mirror', '/input'];
        yield 'a relative container path' => ['/srv/mirror', 'input'];
        yield 'the root' => ['/srv/mirror', '/'];
        yield 'the work area' => ['/srv/mirror', '/work'];
        yield 'inside the work area' => ['/srv/mirror', '/work/repository'];
        yield 'the output directory' => ['/srv/mirror', '/output'];
        yield 'inside the output directory through dots' => ['/srv/mirror', '/input/../output/bundle'];
        yield 'the kept folder' => ['/srv/mirror', '/kept'];
        yield 'inside the kept folder' => ['/srv/mirror', '/kept/vendor'];
    }

    public function testAnAbsoluteKeptFolderIsAccepted(): void
    {
        self::assertSame('/srv/installs/1', new JobKeptFolder('/srv/installs/1')->hostPath);
    }

    public function testARelativeKeptFolderIsRefused(): void
    {
        $this->expectExceptionObject(JobRefused::forKeptFolder('installs/1'));

        new JobKeptFolder('installs/1');
    }

    #[DataProvider('refusedInputs')]
    public function testAnInputThatCouldHideTheWorkAreaOrTheOutputIsRefused(string $hostPath, string $containerPath): void
    {
        $this->expectExceptionObject(JobRefused::forInput($hostPath, $containerPath));

        new JobInput($hostPath, $containerPath);
    }

    public function testTheSmallestPositiveLimitsAreAccepted(): void
    {
        $limits = new JobLimits(1, 1e-9, 1);

        self::assertSame(1, $limits->seconds);
        self::assertSame(1, $limits->nanoCpus);
    }

    public function testTheLargestCpuLimitTheEngineCanTakeIsAccepted(): void
    {
        $limits = new JobLimits(1, 9.2e9, 1);

        self::assertSame(9_200_000_000_000_000_000, $limits->nanoCpus);
    }

    /**
     * @return iterable<string, array{int, float, int}>
     */
    public static function refusedLimits(): iterable
    {
        yield 'no memory' => [0, 1.0, 60];
        yield 'no CPU' => [1024, 0.0, 60];
        yield 'negative CPU' => [1024, -1.0, 60];
        yield 'CPU that rounds to no nanoCPU' => [1024, 1e-12, 60];
        yield 'CPU not a number' => [1024, NAN, 60];
        yield 'infinite CPU' => [1024, INF, 60];
        yield 'CPU past the largest integer in nanoCPUs' => [1024, 9.3e9, 60];
        yield 'no run time' => [1024, 1.0, 0];
    }

    #[DataProvider('refusedLimits')]
    public function testALimitThatCannotBeEnforcedIsRefused(int $memoryBytes, float $cpus, int $seconds): void
    {
        $this->expectExceptionObject(JobRefused::forLimits());

        new JobLimits($memoryBytes, $cpus, $seconds);
    }

    public function testAnOutputGivesTheContentsOfAFileByItsName(): void
    {
        $output = new JobOutput(new JobOutputFile('7', 'seven'), new JobOutputFile('result.json', '{}'));

        self::assertSame('seven', $output->contents('7'));
        self::assertNull($output->contents('missing'));
    }
}
