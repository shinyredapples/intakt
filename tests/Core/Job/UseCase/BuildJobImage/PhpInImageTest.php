<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImageNotBuilt;
use Intakt\Core\Job\UseCase\BuildJobImage\PhpInImage;
use Intakt\Core\Job\UseCase\BuildJobImage\PhpProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpInImage::class)]
#[UsesClass(JobImageNotBuilt::class)]
#[UsesClass(PhpProfile::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobOutput::class)]
#[UsesClass(JobResult::class)]
#[UsesClass(JobSpec::class)]
final class PhpInImageTest extends TestCase
{
    private const string COMPOSER_JSON = '{"require": {"php": ">=8.5.12", "ext-intl": "*", "ext-pdo_sqlite": "*"}}';

    public function testAnImageWithTheRightPhpAndEveryExtensionPassesWithItsVersion(): void
    {
        $version = $this->verify('{"version": "8.5.12", "extensions": ["core", "intl", "pdo_sqlite"]}');

        self::assertSame('8.5.12', $version);
    }

    public function testTheProbeRunsInTheImageWithoutNetwork(): void
    {
        $runner = $this->createMock(JobRunner::class);
        $runner->expects($this->once())->method('run')
            ->with(self::callback(static fn (JobSpec $spec): bool => 'intakt-job/demo:main' === $spec->image && $spec->egress->isNone() && [] === $spec->inputs && 'php' === $spec->command[0]))
            ->willReturn($this->resultWith('{"version": "8.5.12", "extensions": ["intl", "pdo_sqlite"]}'));

        new PhpInImage($runner)->verify('intakt-job/demo:main', PhpProfile::fromComposerJson(self::COMPOSER_JSON));
    }

    public function testAnotherPhpIsRefusedWithBothVersions(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::anotherPhp('intakt-job/demo:main', '8.4.19', '>=8.5.12'));

        $this->verify('{"version": "8.4.19", "extensions": ["intl", "pdo_sqlite"]}');
    }

    public function testAMissingExtensionIsRefusedWithItsName(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::missingExtensions('intakt-job/demo:main', ['pdo_sqlite']));

        $this->verify('{"version": "8.5.12", "extensions": ["intl"]}');
    }

    public function testAProbeThatFailsIsRefusedWithItsEnd(): void
    {
        $runner = self::createStub(JobRunner::class);
        $end = new JobEnd(JobCause::Exited, 127);
        $runner->method('run')->willReturn(new JobResult($end, new JobOutput([])));

        $this->expectExceptionObject(JobImageNotBuilt::probeFailed('intakt-job/demo:main', $end));

        new PhpInImage($runner)->verify('intakt-job/demo:main', PhpProfile::fromComposerJson(self::COMPOSER_JSON));
    }

    public function testAProbeAnswerWithoutAVersionIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::probeUnreadable('intakt-job/demo:main'));

        $this->verify('{"version": "eight", "extensions": ["intl"]}');
    }

    public function testAProbeAnswerWithAnExtensionThatIsNoTextIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::probeUnreadable('intakt-job/demo:main'));

        $this->verify('{"version": "8.5.12", "extensions": ["intl", 7]}');
    }

    private function verify(string $answer): string
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturn($this->resultWith($answer));

        return new PhpInImage($runner)->verify('intakt-job/demo:main', PhpProfile::fromComposerJson(self::COMPOSER_JSON));
    }

    private function resultWith(string $answer): JobResult
    {
        return new JobResult(new JobEnd(JobCause::Exited, 0), new JobOutput(['php.json' => $answer]));
    }
}
