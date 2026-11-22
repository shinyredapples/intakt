<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Domain\DependencySnapshotKey;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLease;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLinks;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotPins;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshots;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotUses;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\RunCheckJob\CheckDependencies;
use Intakt\Core\HandBack\UseCase\RunCheckJob\CheckedLockFile;
use Intakt\Core\HandBack\UseCase\RunCheckJob\CheckedLockFiles;
use Intakt\Core\HandBack\UseCase\RunCheckJob\CheckSnapshot;
use Intakt\Core\HandBack\UseCase\RunCheckJob\DependencyJobs;
use Intakt\Core\HandBack\UseCase\RunCheckJob\LockInputs;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobKeptFolder;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Plugin\Gate\Contract\DependencyStep;
use Intakt\Plugin\Gate\Contract\Gate;
use Intakt\Plugin\PhpGate\Contract\PhpGateSteps;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\HandBack\BuildHistory;
use Intakt\Tests\Core\HandBack\CheckJobsFor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(CheckDependencies::class)]
#[UsesClass(CheckSnapshot::class)]
#[UsesClass(CheckedLockFile::class)]
#[UsesClass(CheckedLockFiles::class)]
#[UsesClass(DependencyJobs::class)]
#[UsesClass(LockInputs::class)]
#[UsesClass(RepositoryGit::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(Paths::class)]
#[UsesClass(CheckJob::class)]
#[UsesClass(HandBack::class)]
#[UsesClass(JobCredentials::class)]
#[UsesClass(DependencySnapshots::class)]
#[UsesClass(DependencySnapshotKey::class)]
#[UsesClass(DependencySnapshotLease::class)]
#[UsesClass(DependencySnapshotLinks::class)]
#[UsesClass(DependencySnapshotPins::class)]
#[UsesClass(DependencySnapshotUses::class)]
#[UsesClass(DependencyStep::class)]
#[UsesClass(PhpGate::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobInput::class)]
#[UsesClass(JobKeptFolder::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(JobResult::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobOutput::class)]
final class CheckDependenciesTest extends TestCase
{
    private const string PLATFORM = '8.5.12';

    private BuildHistory $history;

    private string $folder;

    private string $platform = self::PLATFORM;

    /** @var list<JobSpec> */
    private array $ran = [];

    public function testAMissInstallsAndSavesTheSnapshotThroughTheRunner(): void
    {
        $snapshot = $this->leaseFor($this->head());

        self::assertInstanceOf(CheckSnapshot::class, $snapshot);
        self::assertSame(['probe', 'install'], $this->ranKinds());
        self::assertFileExists($snapshot->lease->folder.'/vendor/composer/installed.json');
        $snapshot->release();
    }

    public function testAnUnchangedLockFileHitsTheSavedSnapshot(): void
    {
        $head = $this->head();
        $this->leaseFor($head)?->release();
        $this->ran = [];

        $snapshot = $this->leaseFor($this->history->commit('Change nothing locked'));

        self::assertInstanceOf(CheckSnapshot::class, $snapshot);
        self::assertSame(['probe'], $this->ranKinds());
        $snapshot->release();
    }

    public function testAChangedLockFileMissesTheSnapshotAnUnchangedOneSaved(): void
    {
        $first = $this->leaseFor($this->head());
        $this->ran = [];

        $second = $this->leaseFor($this->history->commitFile('composer.lock', "{\"packages\": [\"psr/log\"]}\n", 'Require a package'));

        self::assertInstanceOf(CheckSnapshot::class, $first);
        self::assertInstanceOf(CheckSnapshot::class, $second);
        self::assertSame(['probe', 'install'], $this->ranKinds());
        self::assertNotSame($first->lease->folder, $second->lease->folder);
        $first->release();
        $second->release();
    }

    public function testAnotherPlatformMissesTheSnapshotOfTheSameLockFiles(): void
    {
        $head = $this->head();
        $this->leaseFor($head)?->release();
        $this->ran = [];
        $this->platform = '8.4.1';

        $this->leaseFor($head)?->release();

        self::assertSame(['probe', 'install'], $this->ranKinds());
    }

    public function testTheProbeRunsTheStepsPlatformProbeInTheChecksImage(): void
    {
        $this->leaseFor($this->head())?->release();

        self::assertSame('job-image', $this->ran[0]->image);
        self::assertSame(PhpGateSteps::PHP_VERSION_PROBE, $this->ran[0]->command[4]);
    }

    public function testTheInstallGetsTheStepsProbeInstallAndLockFiles(): void
    {
        $this->leaseFor($this->head())?->release();

        self::assertSame('job-image', $this->ran[1]->image);
        self::assertSame([DependencyJobs::LOCK, PhpGateSteps::PHP_VERSION_PROBE, PhpGateSteps::LOCKED_INSTALL, 'composer.json', 'composer.lock'], array_slice($this->ran[1]->command, 4));
    }

    public function testTheSnapshotCarriesTheProbedPlatformAndTheHeadsLockFiles(): void
    {
        $head = $this->head();

        $snapshot = $this->leaseFor($head);

        self::assertInstanceOf(CheckSnapshot::class, $snapshot);
        self::assertSame(self::PLATFORM, $snapshot->platform);
        self::assertSame(trim($this->history->inMirror('rev-parse', $head.':composer.lock')), $snapshot->lockFiles['composer.lock']->blob);
        $snapshot->release();
    }

    public function testAGateWithoutADependencyStepGetsNoProbeAndNoSnapshot(): void
    {
        $gate = self::createStub(Gate::class);
        $gate->method('dependencyStep')->willReturn(null);

        self::assertNull($this->dependencies($gate)->leaseFor($this->job(), $this->history->mirror, $this->head()));
        self::assertSame([], $this->ran);
    }

    public function testLockFilesThatCannotBeReusedGetNoProbeAndNoSnapshot(): void
    {
        $head = $this->head();
        $this->history->checkout('main');
        $this->history->commitFile('composer.lock', "{\"packages\": [\"psr/log\"]}\n", 'Move main on');
        $this->history->push('main');

        self::assertNull($this->leaseFor($head));
        self::assertSame([], $this->ran);
    }

    public function testAFailedProbeGetsNoInstallAndNoSnapshot(): void
    {
        $this->platform = "not a version\n";

        self::assertNull($this->leaseFor($this->head()));
        self::assertSame(['probe'], $this->ranKinds());
    }

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
        $this->folder = $this->history->folder.'/check-jobs';
    }

    protected function tearDown(): void
    {
        // Writable again first, because the store keeps every snapshot read-only.
        if (is_dir($this->folder)) {
            new Filesystem()->chmod($this->folder, 0o700, 0o000, true);
        }

        $this->history->remove();
    }

    private function head(): string
    {
        $this->history->checkout('main');
        $this->history->commitFile('composer.json', "{}\n", 'Add the manifest');
        $this->history->commitFile('composer.lock', "{\"packages\": []}\n", 'Add the lock file');
        $this->history->push('main');
        $this->history->startBranch('task', 'main');

        return $this->history->commit('Work on the task');
    }

    private function leaseFor(string $head): ?CheckSnapshot
    {
        return $this->dependencies(new PhpGate())->leaseFor($this->job(), $this->history->mirror, $head);
    }

    private function dependencies(Gate $gate): CheckDependencies
    {
        // In the mirror, because the check reads the head there once the hand-back is fetched.
        $this->history->push('task');
        $checks = new CheckJobsFor($this->folder);
        $jobs = new DependencyJobs($this->runner(), $gate, $checks->snapshots(), new Filesystem(), $checks->locks(), CheckJobsFor::INSTALL_SCRIPT, $this->folder.'/dependency-installs');

        return new CheckDependencies($gate, new CheckedLockFiles(new RepositoryGit(new HardenedGit())), $jobs, $checks->snapshots());
    }

    private function runner(): JobRunner
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec): JobResult {
            $this->ran[] = $spec;

            if ($spec->kept instanceof JobKeptFolder) {
                new Filesystem()->dumpFile($spec->kept->hostPath.'/vendor/composer/installed.json', "{\"packages\": []}\n");
            }

            return new JobResult(new JobEnd(JobCause::Exited, 0), new JobOutput(['install-result' => "installed\n", 'platform' => $this->platform]));
        });

        return $runner;
    }

    private function job(): CheckJob
    {
        return new CheckJob(new HandBack($this->history->mirror, 'task', $this->history->folder.'/unused.bundle', false, new JobCredentials()), 'job-image', null, new Paths());
    }

    /**
     * @return list<string>
     */
    private function ranKinds(): array
    {
        return array_map(static fn (JobSpec $spec): string => strstr($spec->id->value, '-', true) ?: '', $this->ran);
    }
}
