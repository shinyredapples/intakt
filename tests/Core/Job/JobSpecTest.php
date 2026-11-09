<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Contract\EgressPolicy;
use Intakt\Core\Job\Contract\InvalidJobSpec;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobPaths;
use Intakt\Core\Job\Contract\JobSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobSpec::class)]
#[CoversClass(JobInput::class)]
#[CoversClass(JobPaths::class)]
final class JobSpecTest extends TestCase
{
    public function testAJobWithAnAllowlistReachesItsVendorFirstAndThenTheRegistries(): void
    {
        $spec = JobSpecBuilder::in('/work/out')->build();

        self::assertSame(['api.vendor.example', 'registry.example', 'packages.example'], $spec->allowedHosts());
    }

    public function testAJobWithoutEgressReachesNoHost(): void
    {
        self::assertSame([], JobSpecBuilder::in('/work/out')->withoutEgress()->build()->allowedHosts());
    }

    #[DataProvider('unsafeIds')]
    public function testAnIdThatCannotBeAContainerNameIsRefused(string $id): void
    {
        $this->expectException(InvalidJobSpec::class);

        JobSpecBuilder::in('/work/out')->named($id)->build();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeIds(): iterable
    {
        yield 'empty' => [''];
        yield 'a path' => ['../x'];
        yield 'an upper-case letter' => ['Build'];
        yield 'a space' => ['a b'];
        yield 'too long' => [str_repeat('a', 42)];
    }

    public function testAnIdAtTheLengthLimitIsAccepted(): void
    {
        self::assertSame(str_repeat('a', 41), JobSpecBuilder::in('/work/out')->named(str_repeat('a', 41))->build()->id);
    }

    #[DataProvider('mountsOverTheJobsOwnPlaces')]
    public function testAnInputOverAPlaceTheJobContainerUsesItselfIsRefused(string $containerPath): void
    {
        $this->expectException(InvalidJobSpec::class);

        new JobInput('/srv/data', $containerPath);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mountsOverTheJobsOwnPlaces(): iterable
    {
        yield 'the output' => [JobPaths::OUTPUT];
        yield 'inside the output' => [JobPaths::OUTPUT.'/in'];
        yield 'the work area' => [JobPaths::WORK.'/'];
        yield 'the home' => [JobPaths::HOME];
        yield 'the root' => ['/'];
    }

    public function testAnInputBesideThePlacesTheJobUsesIsAccepted(): void
    {
        self::assertSame('/outputs', new JobInput('/srv/data', '/outputs')->containerPath);
    }

    #[DataProvider('pathsThatHideWhereTheyLead')]
    public function testAnInputPathWithADotSegmentOrAControlCharacterIsRefused(string $hostPath, string $containerPath): void
    {
        $this->expectException(InvalidJobSpec::class);

        new JobInput($hostPath, $containerPath);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pathsThatHideWhereTheyLead(): iterable
    {
        yield 'a container path that climbs into the output' => ['/srv/data', '/x/../output'];
        yield 'a container path with a dot segment' => ['/srv/data', '/./output'];
        yield 'a container path with a newline' => ['/srv/data', "/in\nput"];
        yield 'a container path with a tab' => ['/srv/data', "/in\tput"];
        yield 'a host path that climbs' => ['/jobs/a/..', '/in'];
        yield 'a host path with a NUL byte' => ["/srv/da\0ta", '/in'];
    }

    public function testDotsInsideANameAreNotASegmentAndAreAccepted(): void
    {
        $input = new JobInput('/srv/.cache/a..b', '/in/.config');

        self::assertSame('/srv/.cache/a..b', $input->hostPath);
    }

    public function testAHostPathThatClimbsBackIntoTheOutputDirectoryIsRefused(): void
    {
        $this->expectException(InvalidJobSpec::class);

        new JobSpec('a', 'img', ['x'], [new JobInput('/jobs/other/../a/out', '/in')], '/jobs/a/out', 1, 1.0, 1, EgressPolicy::None);
    }

    public function testAnOutputDirectoryWithADotSegmentIsRefused(): void
    {
        $this->expectException(InvalidJobSpec::class);

        JobSpecBuilder::in('/jobs/a/../b')->build();
    }

    public function testAJobOnOtherPathsKeepsEverythingElseAndIsCheckedAgain(): void
    {
        $spec = JobSpecBuilder::in('/work/out')->build();

        $moved = $spec->withPaths([new JobInput('/srv/in', '/in')], '/real/out');

        self::assertSame(['/real/out', '/srv/in', $spec->allowedHosts()], [$moved->outputDir, $moved->inputs[0]->hostPath, $moved->allowedHosts()]);
        $this->expectException(InvalidJobSpec::class);
        $spec->withPaths([new JobInput('/real', '/in')], '/real/out');
    }

    public function testAnInputThatHoldsTheOutputDirectoryIsRefused(): void
    {
        $this->expectException(InvalidJobSpec::class);

        JobSpecBuilder::in('/work/jobs/1/out')->withInput('/work/jobs', '/jobs')->build();
    }

    public function testAnInputThatSitsInsideTheOutputDirectoryIsRefused(): void
    {
        $this->expectException(InvalidJobSpec::class);

        JobSpecBuilder::in('/work/jobs/1/out')->withInput('/work/jobs/1/out/sub', '/sub')->build();
    }

    public function testAnInputBesideTheOutputDirectoryWithTheSameNamePrefixIsAccepted(): void
    {
        $spec = JobSpecBuilder::in('/work/out')->withInput('/work/out-other', '/other')->build();

        self::assertCount(1, $spec->inputs);
    }

    public function testAnAllowlistWithAHostThatIsNotAPlainNameIsRefused(): void
    {
        $this->expectException(InvalidJobSpec::class);

        new JobSpec('a', 'img', ['x'], [], '/o', 1, 1.0, 1, EgressPolicy::Allowlist, 'api.example', ['evil.example/path']);
    }

    public function testAJobWithoutEgressThatNamesAHostIsRefused(): void
    {
        $this->expectException(InvalidJobSpec::class);

        new JobSpec('a', 'img', ['x'], [], '/o', 1, 1.0, 1, EgressPolicy::None, 'api.example');
    }

    public function testALimitOfZeroIsRefused(): void
    {
        $this->expectException(InvalidJobSpec::class);

        new JobSpec('a', 'img', ['x'], [], '/o', 0, 1.0, 1, EgressPolicy::None);
    }
}
