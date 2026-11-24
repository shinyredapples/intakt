<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\CheckJobOutcome;
use Intakt\Core\HandBack\Contract\CheckVerdict;
use Intakt\Core\HandBack\Contract\HandBack;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Domain\AttributionLines;
use Intakt\Core\HandBack\Domain\BundleHeader;
use Intakt\Core\HandBack\Domain\DependencySnapshotKey;
use Intakt\Core\HandBack\Domain\LostContent;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLease;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLinks;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotPins;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshots;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotUses;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleFile;
use Intakt\Core\HandBack\UseCase\CheckHandBack\BundleSecretScan;
use Intakt\Core\HandBack\UseCase\CheckHandBack\CheckHandBack;
use Intakt\Core\HandBack\UseCase\CheckHandBack\GivenHistory;
use Intakt\Core\HandBack\UseCase\CheckHandBack\IncomingObjects;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\CheckHandBack\StrippedCommits;
use Intakt\Core\HandBack\UseCase\RunCheckJob\ChangedFile;
use Intakt\Core\HandBack\UseCase\RunCheckJob\CheckDependencies;
use Intakt\Core\HandBack\UseCase\RunCheckJob\CheckedLockFile;
use Intakt\Core\HandBack\UseCase\RunCheckJob\CheckedLockFiles;
use Intakt\Core\HandBack\UseCase\RunCheckJob\CheckSnapshot;
use Intakt\Core\HandBack\UseCase\RunCheckJob\DependencyJobs;
use Intakt\Core\HandBack\UseCase\RunCheckJob\LockInputs;
use Intakt\Core\HandBack\UseCase\RunCheckJob\LostContentCheck;
use Intakt\Core\HandBack\UseCase\RunCheckJob\RunCheckJob;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobKeptFolder;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Core\Job\Contract\EgressHosts;
use Intakt\Plugin\Gate\Contract\CheckSteps;
use Intakt\Plugin\Gate\Contract\DependencyStep;
use Intakt\Plugin\Gate\Contract\Gate;
use Intakt\Plugin\Gate\Contract\LockFiles;
use Intakt\Plugin\PhpGate\Contract\PhpGateSteps;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\HandBack\BuildHistory;
use Intakt\Tests\Core\HandBack\CheckJobsFor;
use Intakt\Tests\Core\Job\OutputFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(RunCheckJob::class)]
#[CoversClass(CheckDependencies::class)]
#[CoversClass(CheckedLockFiles::class)]
#[CoversClass(DependencyJobs::class)]
#[CoversClass(LockInputs::class)]
#[CoversClass(CheckSnapshot::class)]
#[CoversClass(CheckedLockFile::class)]
#[UsesClass(DependencySnapshots::class)]
#[UsesClass(DependencySnapshotKey::class)]
#[UsesClass(DependencySnapshotLease::class)]
#[UsesClass(DependencySnapshotLinks::class)]
#[UsesClass(DependencySnapshotPins::class)]
#[UsesClass(DependencySnapshotUses::class)]
#[UsesClass(DependencyStep::class)]
#[UsesClass(LostContentCheck::class)]
#[UsesClass(ChangedFile::class)]
#[UsesClass(CheckJobOutcome::class)]
#[UsesClass(CheckVerdict::class)]
#[UsesClass(CheckJob::class)]
#[UsesClass(Paths::class)]
#[UsesClass(LostContent::class)]
#[UsesClass(PhpGate::class)]
#[UsesClass(CheckHandBack::class)]
#[UsesClass(BundleFile::class)]
#[UsesClass(GivenHistory::class)]
#[UsesClass(IncomingObjects::class)]
#[UsesClass(BundleSecretScan::class)]
#[UsesClass(JobCredentials::class)]
#[UsesClass(RepositoryGit::class)]
#[UsesClass(StrippedCommits::class)]
#[UsesClass(AcceptedHandBack::class)]
#[UsesClass(HandBack::class)]
#[UsesClass(AttributionLines::class)]
#[UsesClass(BundleHeader::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobInput::class)]
#[UsesClass(JobKeptFolder::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(JobResult::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobOutput::class)]
final class RunCheckJobDependenciesTest extends TestCase
{
    private const string PLATFORM = '8.5.12';

    private const string MANIFEST = "{\"require\": {\"psr/log\": \"^3.0\"}}\n";

    private const string LOCK = "{\"packages\": [{\"name\": \"psr/log\"}], \"packages-dev\": []}\n";

    private BuildHistory $history;

    private CheckJobsFor $checks;

    /** @var list<JobSpec> */
    private array $ran = [];

    /** @var array<string, string> */
    private array $lockFilesTheInstallSaw = [];

    private ?bool $pinHeldDuringTheCheck = null;

    private string $installPlatform = self::PLATFORM;

    private string $installResult = "installed\n";

    private ?string $probedPlatform = self::PLATFORM;

    private bool $checkThrows = false;

    private bool $probeRefused = false;

    private bool $linkOut = false;

    public function testACheckWhoseLockInputsHaveNoSnapshotRunsTheInstallJobAndSavesItsInstallUnderTheKey(): void
    {
        $this->givenMainLocks(self::LOCK);
        $this->history->commit('Build work');

        $outcome = $this->whenTheCheckRuns();

        self::assertSame(CheckVerdict::Passed, $outcome instanceof CheckJobOutcome ? $outcome->verdict : null);
        self::assertSame(['probe', 'install', 'check'], $this->jobsThatRan());
        self::assertSame(['composer.json' => self::MANIFEST, 'composer.lock' => self::LOCK], $this->lockFilesTheInstallSaw);
        $install = $this->ran[1];
        self::assertEquals([new JobInput($this->history->folder.'/dependency-installs/'.$this->key(self::LOCK)->value.'/lock', '/input/lock')], $install->inputs);
        self::assertSame(['/input/lock', PhpGateSteps::PHP_VERSION_PROBE, PhpGateSteps::LOCKED_INSTALL, 'composer.json', 'composer.lock'], array_slice([...$install->command], 4));
        self::assertSame(['repo.packagist.org', 'api.github.com', 'codeload.github.com'], [...$install->egress->hosts]);
        self::assertFileExists($this->checks->snapshotsFolder().'/'.$this->key(self::LOCK)->value.'/vendor/composer/installed.json');
        self::assertDirectoryDoesNotExist($this->history->folder.'/dependency-installs/'.$this->key(self::LOCK)->value);
    }

    public function testACheckOnANewInstallMountsItsSnapshotReadOnlyAndNamesTheKeysPlatformAndTheProbeForTheScriptToCheckAgain(): void
    {
        $this->givenMainLocks(self::LOCK);
        $this->history->commit('Build work');

        $this->whenTheCheckRuns();

        $check = $this->checkSpec();
        self::assertEquals(new JobInput($this->checks->snapshotsFolder().'/'.$this->key(self::LOCK)->value, '/input/dependencies'), array_last($check->inputs));
        self::assertSame(
            ['task', '--dependencies', '/input/dependencies', self::PLATFORM, PhpGateSteps::PHP_VERSION_PROBE, PhpGateSteps::INSTALL, PhpGateSteps::GENERATE, 'composer.json', $this->blobOf(self::MANIFEST), 'composer.lock', $this->blobOf(self::LOCK), '--', PhpGateSteps::INSTALL, PhpGateSteps::CHECK],
            array_slice([...$check->command], 6),
        );
    }

    public function testACheckOnASnapshotOfAGateWhoseGenerateStepDiffersFromItsInstallNamesBothForTheScriptToSwap(): void
    {
        $this->history->checkout('main');
        $this->history->commitFile('package-lock.json', "{}\n", 'Lock the packages');
        $this->history->push('main');
        $this->history->startBranch('task', 'main');
        $this->history->commit('Build work');
        $gate = self::createStub(Gate::class);
        $gate->method('checkSteps')->willReturn(new CheckSteps('npm ci', 'npm test'));
        $gate->method('registryHosts')->willReturn(new EgressHosts());
        $gate->method('dependencyStep')->willReturn(new DependencyStep(new LockFiles('package-lock.json'),'node --version', 'npm ci --ignore-scripts', 'npm rebuild', 'npm ci'));

        $this->whenTheCheckRuns(gate: $gate);

        self::assertSame(['probe', 'install', 'check'], $this->jobsThatRan());
        self::assertSame(
            ['task', '--dependencies', '/input/dependencies', self::PLATFORM, 'node --version', 'npm ci', 'npm rebuild', 'package-lock.json', $this->blobOf("{}\n"), '--', 'npm ci', 'npm test'],
            array_slice([...$this->checkSpec()->command], 6),
        );
    }

    public function testACheckWhoseLockInputsHaveASnapshotMountsItAndRunsNoInstallJob(): void
    {
        $this->givenMainLocks(self::LOCK);
        $this->history->commit('Build work');
        $this->whenTheCheckRuns();
        $this->ran = [];
        $this->history->commitFile('feature.txt', "more\n", 'More build work');

        $outcome = $this->whenTheCheckRuns();

        self::assertSame(CheckVerdict::Passed, $outcome instanceof CheckJobOutcome ? $outcome->verdict : null);
        self::assertSame(['probe', 'check'], $this->jobsThatRan());
        self::assertContains('--dependencies', [...$this->checkSpec()->command]);
    }

    public function testACheckAfterAChangedLockFileMissesTheSnapshotOfTheEarlierLockAndInstalls(): void
    {
        $this->givenMainLocks(self::LOCK);
        $this->history->commit('Build work');
        $this->whenTheCheckRuns();
        $this->ran = [];
        $changed = "{\"packages\": [{\"name\": \"psr/log\"}, {\"name\": \"psr/clock\"}], \"packages-dev\": []}\n";
        $this->history->commitFile('composer.lock', $changed, 'Lock another package');

        $this->whenTheCheckRuns();

        self::assertSame(['probe', 'install', 'check'], $this->jobsThatRan());
        self::assertSame($changed, $this->lockFilesTheInstallSaw['composer.lock']);
        self::assertDirectoryExists($this->checks->snapshotsFolder().'/'.$this->key($changed)->value);
    }

    public function testAnInstallTheLockGuardRefusedIsNotSavedAndTheCheckInstallsItself(): void
    {
        $this->givenMainLocks(self::LOCK);
        $this->history->commit('Build work');
        $this->installResult = "failed 78\n";

        $this->whenTheCheckRuns();

        self::assertSame(['probe', 'install', 'check'], $this->jobsThatRan());
        self::assertNotContains('--dependencies', [...$this->checkSpec()->command]);
        self::assertNull($this->checks->snapshots()->lease($this->key(self::LOCK)));
    }

    public function testAnInstallMadeOnAnotherPlatformThanTheProbedOneIsNotSaved(): void
    {
        $this->givenMainLocks(self::LOCK);
        $this->history->commit('Build work');
        $this->installPlatform = '8.4.0';

        $this->whenTheCheckRuns();

        self::assertNotContains('--dependencies', [...$this->checkSpec()->command]);
        self::assertNull($this->checks->snapshots()->lease($this->key(self::LOCK)));
    }

    public function testAnInstallWithALinkOutOfItIsRefusedByTheStoreAndTheCheckInstallsItself(): void
    {
        $this->givenMainLocks(self::LOCK);
        $this->history->commit('Build work');
        $this->linkOut = true;

        $outcome = $this->whenTheCheckRuns();

        self::assertSame(CheckVerdict::Passed, $outcome instanceof CheckJobOutcome ? $outcome->verdict : null);
        self::assertNotContains('--dependencies', [...$this->checkSpec()->command]);
        self::assertNull($this->checks->snapshots()->lease($this->key(self::LOCK)));
        self::assertDirectoryDoesNotExist($this->history->folder.'/dependency-installs/'.$this->key(self::LOCK)->value);
    }

    public function testAProbeThatPrintsNoUsablePlatformRunsNoInstallJob(): void
    {
        $this->givenMainLocks(self::LOCK);
        $this->history->commit('Build work');

        foreach ([null, '', "8.5.12\n", str_repeat('8', 65)] as $platform) {
            $this->ran = [];
            $this->probedPlatform = $platform;

            $this->whenTheCheckRuns();

            self::assertSame(['probe', 'check'], $this->jobsThatRan(), (string) $platform);
        }
    }

    public function testAProbeOfTheSameHeadStillRunningLeavesTheCheckToInstallItselfWithoutASnapshot(): void
    {
        $this->givenMainLocks(self::LOCK);
        $this->history->commit('Build work');
        $this->probeRefused = true;

        $outcome = $this->whenTheCheckRuns();

        self::assertSame(CheckVerdict::Passed, $outcome instanceof CheckJobOutcome ? $outcome->verdict : null);
        self::assertSame(['probe', 'check'], $this->jobsThatRan());
    }

    public function testACheckWithAConfiguredCommandUsesNoSnapshotAndRunsOnlyTheCheckJob(): void
    {
        $this->givenMainLocks(self::LOCK);
        $this->history->commit('Build work');

        $this->whenTheCheckRuns('make check');

        self::assertSame(['check'], $this->jobsThatRan());
        self::assertSame(['task', '--', 'make check'], array_slice([...$this->checkSpec()->command], 6));
    }

    public function testATreeWithoutALockFileRunsOnlyTheCheckJob(): void
    {
        $this->history->commitFile('composer.json', self::MANIFEST, 'Add a manifest alone');

        $this->whenTheCheckRuns();

        self::assertSame(['check'], $this->jobsThatRan());
    }

    public function testABranchBehindMainThatChangedItsLockFileWhereMainDidTooRunsOnlyTheCheckJob(): void
    {
        $this->givenMainLocks(self::LOCK);
        $this->history->commitFile('composer.lock', "{\"packages\": [], \"packages-dev\": []}\n", 'Unlock the package');
        $this->history->checkout('main');
        $this->history->commitFile('composer.lock', "{\"packages\": [{\"name\": \"psr/clock\"}], \"packages-dev\": []}\n", 'Lock another package on main');
        $this->history->push('main');
        $this->history->checkout('task');

        $this->whenTheCheckRuns();

        self::assertSame(['check'], $this->jobsThatRan());
    }

    public function testABranchBehindMainWithMainsOwnLockFilesIsKeyedOnThem(): void
    {
        $this->givenMainLocks(self::LOCK);
        $this->history->commit('Build work');
        $this->history->checkout('main');
        $this->history->commitFile('other.txt', "main moved\n", 'Move main');
        $this->history->push('main');
        $this->history->checkout('task');

        $this->whenTheCheckRuns();

        self::assertSame(['probe', 'install', 'check'], $this->jobsThatRan());
        self::assertSame(self::LOCK, $this->lockFilesTheInstallSaw['composer.lock']);
    }

    public function testTheSnapshotStaysPinnedWhileTheCheckJobRunsAndIsReleasedAfterIt(): void
    {
        $this->givenMainLocks(self::LOCK);
        $this->history->commit('Build work');

        $this->whenTheCheckRuns();

        self::assertTrue($this->pinHeldDuringTheCheck);
        self::assertTrue($this->pinIsFree());
    }

    public function testTheSnapshotIsReleasedAlsoWhenTheCheckJobThrows(): void
    {
        $this->givenMainLocks(self::LOCK);
        $this->history->commit('Build work');
        $this->checkThrows = true;

        try {
            $this->whenTheCheckRuns();
            self::fail('The engine failure did not reach the caller.');
        } catch (DockerEngineFailed) {
            self::assertTrue($this->pinHeldDuringTheCheck);
            self::assertTrue($this->pinIsFree());
        }
    }

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
        $this->checks = new CheckJobsFor($this->history->folder);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->checks->snapshotsFolder())) {
            new Filesystem()->chmod($this->checks->snapshotsFolder(), 0o700, 0o000, true);
        }

        $this->history->remove();
    }

    private function givenMainLocks(string $lock): void
    {
        $this->history->checkout('main');
        $this->history->commitFile('composer.json', self::MANIFEST, 'Add the manifest');
        $this->history->commitFile('composer.lock', $lock, 'Lock the dependencies');
        $this->history->push('main');
        $this->history->startBranch('task', 'main');
    }

    private function whenTheCheckRuns(?string $command = null, ?Gate $gate = null): CheckJobOutcome|RefusedHandBack
    {
        $job = new CheckJob(new HandBack($this->history->mirror, 'task', $this->history->bundle('main..task'), false, new JobCredentials()), 'job-image', $command, new Paths());

        return $this->checks->runCheckJob($this->runner(), $gate)->run($job);
    }

    private function runner(): JobRunner
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec): JobResult {
            $this->ran[] = $spec;

            return match (true) {
                str_starts_with($spec->id->value, 'probe-') => $this->probed(),
                str_starts_with($spec->id->value, 'install-') => $this->installed($spec),
                default => $this->checked($spec),
            };
        });

        return $runner;
    }

    private function probed(): JobResult
    {
        if ($this->probeRefused) {
            throw JobRefused::alreadyRunning(new JobId('probe-head'));
        }

        return new JobResult(new JobEnd(JobCause::Exited, 0), OutputFiles::of(null === $this->probedPlatform ? [] : ['platform' => $this->probedPlatform]));
    }

    private function installed(JobSpec $spec): JobResult
    {
        $lock = $spec->inputs[0]->hostPath;
        $this->lockFilesTheInstallSaw = [];

        foreach (array_diff((array) scandir($lock), ['.', '..']) as $name) {
            $this->lockFilesTheInstallSaw[(string) $name] = (string) file_get_contents($lock.'/'.$name);
        }

        ksort($this->lockFilesTheInstallSaw);
        self::assertInstanceOf(JobKeptFolder::class, $spec->kept);
        new Filesystem()->dumpFile($spec->kept->hostPath.'/vendor/composer/installed.json', "{\"packages\": []}\n");

        if ($this->linkOut) {
            symlink($this->history->folder, $spec->kept->hostPath.'/vendor/out');
        }

        return new JobResult(
            new JobEnd(JobCause::Exited, "installed\n" === $this->installResult ? 0 : 78),
            OutputFiles::of(['install-result' => $this->installResult, 'platform' => $this->installPlatform]),
        );
    }

    private function checked(JobSpec $spec): JobResult
    {
        if (in_array('--dependencies', [...$spec->command], true)) {
            $this->pinHeldDuringTheCheck = !$this->pinIsFree();
        }

        if ($this->checkThrows) {
            throw DockerEngineFailed::noSocket('/var/run/docker.sock');
        }

        return new JobResult(new JobEnd(JobCause::Exited, 0), OutputFiles::of(['check-result' => "passed\n"]));
    }

    private function pinIsFree(): bool
    {
        $pin = new DependencySnapshotPins(new Filesystem())->of($this->checks->snapshotsFolder(), $this->key(self::LOCK)->value);

        if (!$pin->acquire(false)) {
            return false;
        }

        $pin->release();

        return true;
    }

    /**
     * @return list<string>
     */
    private function jobsThatRan(): array
    {
        return array_map(static fn (JobSpec $spec): string => explode('-', $spec->id->value)[0], $this->ran);
    }

    private function key(string $lock): DependencySnapshotKey
    {
        return new LockInputs(['composer.json' => self::MANIFEST, 'composer.lock' => $lock], self::PLATFORM)->key();
    }

    private function checkSpec(): JobSpec
    {
        $check = array_last($this->ran);
        self::assertInstanceOf(JobSpec::class, $check);
        self::assertStringStartsWith('check-', $check->id->value);

        return $check;
    }

    private function blobOf(string $content): string
    {
        return sha1('blob '.strlen($content)."\0".$content);
    }
}
