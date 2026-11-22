<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\HandBack\Domain\DependencySnapshotKey;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLease;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotLinks;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotPins;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshots;
use Intakt\Core\HandBack\Infrastructure\DependencySnapshotUses;
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
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Plugin\Gate\Contract\DependencyStep;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\HandBack\CheckJobsFor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(DependencyJobs::class)]
#[UsesClass(LockInputs::class)]
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
final class DependencyJobsTest extends TestCase
{
    private const string PLATFORM = '8.5.12';

    private string $folder;

    private CheckJobsFor $checks;

    private LockInputs $inputs;

    private DependencyStep $step;

    private int $installJobs = 0;

    private ?bool $secondInstalled = null;

    private bool $firstFilesStayed = false;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-dependency-jobs-'.bin2hex(random_bytes(6));
        $this->checks = new CheckJobsFor($this->folder);
        $this->inputs = new LockInputs(['composer.json' => "{}\n", 'composer.lock' => "{\"packages\": []}\n"], self::PLATFORM);
        $step = new PhpGate()->dependencyStep(null);
        self::assertNotNull($step);
        $this->step = $step;
    }

    protected function tearDown(): void
    {
        if (is_dir($this->folder)) {
            new Filesystem()->chmod($this->folder, 0o700, 0o000, true);
        }

        new Filesystem()->remove($this->folder);
    }

    public function testASecondInstallOfTheSameKeyWhileTheFirstRunsLeavesTheFirstInstallWholeAndSaved(): void
    {
        $first = $this->jobsWith($this->runnerThatStartsASecondInstallOfTheSameKeyInside());

        self::assertTrue($first->installed($this->inputs, $this->step, 'image'));
        self::assertFalse($this->secondInstalled);
        self::assertTrue($this->firstFilesStayed);
        self::assertFileExists($this->checks->snapshotsFolder().'/'.$this->inputs->key()->value.'/vendor/composer/installed.json');
    }

    public function testASecondInstallOfTheSameKeyWhileTheFirstRunsStartsNoJobOfItsOwn(): void
    {
        $this->jobsWith($this->runnerThatStartsASecondInstallOfTheSameKeyInside())->installed($this->inputs, $this->step, 'image');

        self::assertSame(1, $this->installJobs);
    }

    public function testAnInstallOfAnotherKeyRunsWhileTheFirstRuns(): void
    {
        $other = new LockInputs(['composer.json' => "{}\n", 'composer.lock' => "{\"packages\": [{\"name\": \"psr/log\"}]}\n"], self::PLATFORM);
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use ($other): JobResult {
            ++$this->installJobs;

            if (1 === $this->installJobs) {
                $this->secondInstalled = $this->jobsWith($this->runnerThatInstalls())->installed($other, $this->step, 'image');
            }

            return $this->installedInto($spec);
        });

        self::assertTrue($this->jobsWith($runner)->installed($this->inputs, $this->step, 'image'));
        self::assertTrue($this->secondInstalled);
    }

    public function testTheSameKeyInstallsAgainOnceTheFirstInstallEnded(): void
    {
        $runner = $this->runnerThatInstalls();

        $this->jobsWith($runner)->installed($this->inputs, $this->step, 'image');

        self::assertTrue($this->jobsWith($runner)->installed($this->inputs, $this->step, 'image'));
        self::assertDirectoryDoesNotExist($this->folder.'/dependency-installs/'.$this->inputs->key()->value);
    }

    public function testAnInstallFolderThatCannotBeMadeLeavesTheCheckToInstallItselfAndTheKeyFreeForTheNextInstall(): void
    {
        new Filesystem()->dumpFile($this->folder.'/dependency-installs', "a file where the install folders go\n");
        $runner = $this->runnerThatInstalls();

        $installed = $this->jobsWith($runner)->installed($this->inputs, $this->step, 'image');
        new Filesystem()->remove($this->folder.'/dependency-installs');

        self::assertFalse($installed);
        self::assertTrue($this->jobsWith($runner)->installed($this->inputs, $this->step, 'image'));
    }

    private function runnerThatStartsASecondInstallOfTheSameKeyInside(): JobRunner
    {
        $runner = self::createStub(JobRunner::class);
        // The runner refuses a second job of the same id, as the container runner does, so only the folder handling decides what the second check breaks.
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use (&$runner): JobResult {
            ++$this->installJobs;

            if ($this->installJobs > 1) {
                throw JobRefused::alreadyRunning($spec->id);
            }

            self::assertInstanceOf(JobKeptFolder::class, $spec->kept);
            $marker = $spec->kept->hostPath.'/vendor/composer/installed.json';
            new Filesystem()->dumpFile($marker, "{\"packages\": []}\n");
            $this->secondInstalled = $this->jobsWith($runner)->installed($this->inputs, $this->step, 'image');
            $this->firstFilesStayed = is_file($marker) && is_dir($spec->inputs[0]->hostPath);

            return $this->resultInstalled();
        });

        return $runner;
    }

    private function runnerThatInstalls(): JobRunner
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(fn (JobSpec $spec): JobResult => $this->installedInto($spec));

        return $runner;
    }

    private function installedInto(JobSpec $spec): JobResult
    {
        self::assertInstanceOf(JobKeptFolder::class, $spec->kept);
        new Filesystem()->dumpFile($spec->kept->hostPath.'/vendor/composer/installed.json', "{\"packages\": []}\n");

        return $this->resultInstalled();
    }

    private function resultInstalled(): JobResult
    {
        return new JobResult(new JobEnd(JobCause::Exited, 0), new JobOutput(['install-result' => "installed\n", 'platform' => self::PLATFORM]));
    }

    // A separate service on the same folders and lock folder, because a second orchestrator process shares only those.
    private function jobsWith(JobRunner $runner): DependencyJobs
    {
        $gate = new PhpGate();

        return new DependencyJobs($runner, $gate, $this->checks->snapshots(), new Filesystem(), $this->checks->locks(), CheckJobsFor::INSTALL_SCRIPT, $this->folder.'/dependency-installs');
    }
}
