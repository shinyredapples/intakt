<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\RunCheckJob;

use Closure;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\RunCheckJob\DependencyInstallJob;
use Intakt\Core\HandBack\UseCase\RunCheckJob\DependencySnapshots;
use Intakt\Core\HandBack\UseCase\RunCheckJob\DependencySource;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobOutputRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Plugin\Gate\Contract\DependencyInstall;
use Intakt\Plugin\Gate\Contract\Gate;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\HandBack\BuildHistory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(DependencySnapshots::class)]
#[CoversClass(DependencyInstallJob::class)]
#[CoversClass(DependencySource::class)]
#[UsesClass(DependencyInstall::class)]
#[UsesClass(PhpGate::class)]
#[UsesClass(RepositoryGit::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobInput::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(JobResult::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobOutput::class)]
final class DependencySnapshotsTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../../../docker/job/install-dependencies.sh';

    private const string MANIFEST = "{\"require\": {}}\n";

    private BuildHistory $history;

    private string $root = '';

    private MockClock $clock;

    private PersistingStoreInterface $locks;

    // Held for the whole test, because a lock nobody references is released at once.
    private ?SharedLockInterface $otherCheck = null;

    /** @var list<JobSpec> */
    private array $ran = [];

    /** @var list<string> */
    private array $lockInputsSeen = [];

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
        $this->root = $this->history->folder.'/dependencies';
        $this->clock = new MockClock('2026-11-18 10:00:00 UTC');
        $this->locks = new InMemoryStore();
    }

    protected function tearDown(): void
    {
        $this->history->remove();
    }

    public function testAMissInstallsFromTheLockFilesAloneAndSavesTheSnapshot(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');

        $snapshot = $this->store($this->runnerInstalling())->of($this->history->work.'/.git', $head, 'job-image');

        self::assertNotNull($snapshot);
        self::assertStringStartsWith($this->root.'/', $snapshot);
        self::assertFileExists($snapshot.'/vendor/acme/lib/Lib.php');
        self::assertCount(1, $this->ran);
        self::assertSame(['composer.json', 'composer.lock'], $this->lockInputsSeen);
        $install = $this->ran[0];
        self::assertCount(1, $install->inputs);
        self::assertSame('/input/lock', $install->inputs[0]->containerPath);
        self::assertSame(['/input/lock', '/kept', 'vendor', '--', 'composer install --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader'], array_slice($install->command, 4));
        self::assertSame((string) file_get_contents(self::SCRIPT), $install->command[2]);
        self::assertSame('job-image', $install->image);
        self::assertSame(['repo.packagist.org', 'api.github.com', 'codeload.github.com'], $install->egress->hosts);
        self::assertDirectoryDoesNotExist($snapshot.'.staging');
    }

    public function testAHitReusesTheSnapshotAndRunsNoInstall(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $store = $this->store($this->runnerInstalling());
        $first = $store->of($this->history->work.'/.git', $head, 'job-image');

        $second = $store->of($this->history->work.'/.git', $this->history->commit('Change only the code'), 'job-image');

        self::assertNotNull($first);
        self::assertSame($first, $second);
        self::assertCount(1, $this->ran);
    }

    public function testAChangedLockFileMissesTheSnapshotOfTheOldOne(): void
    {
        $store = $this->store($this->runnerInstalling());
        $old = $store->of($this->history->work.'/.git', $this->givenTheHeadLocks('{"packages": ["acme/lib"]}'), 'job-image');

        $new = $store->of($this->history->work.'/.git', $this->givenTheHeadLocks('{"packages": ["acme/lib", "acme/other"]}'), 'job-image');

        self::assertNotNull($new);
        self::assertNotSame($old, $new);
        self::assertCount(2, $this->ran);
    }

    public function testAnotherJobImageMissesTheSnapshotBecauseItFixesThePhpVersion(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $store = $this->store($this->runnerInstalling());
        $old = $store->of($this->history->work.'/.git', $head, 'php-8.4-image');

        $new = $store->of($this->history->work.'/.git', $head, 'php-8.5-image');

        self::assertNotSame($old, $new);
        self::assertCount(2, $this->ran);
    }

    public function testAHeadWithoutALockFileGetsNoSnapshotAndNoInstall(): void
    {
        $this->history->commitFile('composer.json', "{}\n", 'Add the manifest alone');

        $snapshot = $this->store($this->runnerInstalling())->of($this->history->work.'/.git', $this->history->commit('Build work'), 'job-image');

        self::assertNull($snapshot);
        self::assertSame([], $this->ran);
    }

    public function testALanguageWithoutSnapshotSupportGetsNoSnapshotAndNoInstall(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $gate = self::createStub(Gate::class);
        $gate->method('dependencyInstall')->willReturn(null);

        $snapshot = $this->store($this->runnerInstalling(), $gate)->of($this->history->work.'/.git', $head, 'job-image');

        self::assertNull($snapshot);
        self::assertSame([], $this->ran);
    }

    public function testAFailedInstallSavesNothing(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $runner = $this->runnerDoing(static function (string $kept): JobResult {
            new Filesystem()->dumpFile($kept.'/vendor/acme/lib/Half.php', "<?php\n");

            return new JobResult(new JobEnd(JobCause::Exited, 1), new JobOutput([]));
        });

        $snapshot = $this->store($runner)->of($this->history->work.'/.git', $head, 'job-image');

        self::assertNull($snapshot);
        self::assertSame([], $this->entries());
    }

    public function testAnInstallThatRanOutOfTimeSavesNothingWhateverItsOutputSays(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $runner = $this->runnerDoing(static function (string $kept): JobResult {
            new Filesystem()->dumpFile($kept.'/vendor/acme/lib/Lib.php', "<?php\n");

            return new JobResult(new JobEnd(JobCause::TimedOut, 137), new JobOutput(['install-result' => "installed\n"]));
        });

        self::assertNull($this->store($runner)->of($this->history->work.'/.git', $head, 'job-image'));
        self::assertSame([], $this->entries());
    }

    public function testAnInstallWhoseOutputTheRunnerRefusesSavesNothing(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willThrowException(JobOutputRefused::tooMany(1000));

        self::assertNull($this->store($runner)->of($this->history->work.'/.git', $head, 'job-image'));
        self::assertSame([], $this->entries());
    }

    public function testASavedSnapshotIsWritableByTheRunnerAlone(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $runner = $this->runnerDoing(static function (string $kept): JobResult {
            $filesystem = new Filesystem();
            $filesystem->dumpFile($kept.'/vendor/acme/lib/Lib.php', "<?php\n");
            $filesystem->dumpFile($kept.'/vendor/bin/tool', "#!/bin/sh\n");
            $filesystem->chmod($kept.'/vendor/acme/lib/Lib.php', 0o666);
            $filesystem->chmod($kept.'/vendor/bin/tool', 0o777);
            $filesystem->chmod($kept.'/vendor/acme', 0o777);

            return new JobResult(new JobEnd(JobCause::Exited, 0), new JobOutput(['install-result' => "installed\n"]));
        });

        $snapshot = (string) $this->store($runner)->of($this->history->work.'/.git', $head, 'job-image');

        self::assertSame(0o755, fileperms($this->root) & 0o777);
        self::assertSame(0o755, fileperms($snapshot) & 0o777);
        self::assertSame(0o755, fileperms($snapshot.'/vendor/acme') & 0o777);
        self::assertSame(0o644, fileperms($snapshot.'/vendor/acme/lib/Lib.php') & 0o777);
        self::assertSame(0o755, fileperms($snapshot.'/vendor/bin/tool') & 0o777);
    }

    public function testALinkTheInstallLeftIsNotFollowedWhenTheSnapshotIsSealed(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $outside = $this->history->folder.'/outside.txt';
        file_put_contents($outside, 'not the store');
        chmod($outside, 0o666);
        $runner = $this->runnerDoing(static function (string $kept) use ($outside): JobResult {
            new Filesystem()->dumpFile($kept.'/vendor/acme/lib/Lib.php', "<?php\n");
            symlink($outside, $kept.'/vendor/acme/link');

            return new JobResult(new JobEnd(JobCause::Exited, 0), new JobOutput(['install-result' => "installed\n"]));
        });

        $snapshot = (string) $this->store($runner)->of($this->history->work.'/.git', $head, 'job-image');

        self::assertTrue(is_link($snapshot.'/vendor/acme/link'));
        clearstatcache();
        self::assertSame(0o666, fileperms($outside) & 0o777);
    }

    public function testTheStoreKeepsOnlyTheMostRecentlyUsedSnapshots(): void
    {
        $store = $this->store($this->runnerInstalling(), kept: 2);
        $first = $store->of($this->history->work.'/.git', $this->givenTheHeadLocks('{"packages": ["first"]}'), 'job-image');
        $this->clock->sleep(60);
        $second = $store->of($this->history->work.'/.git', $this->givenTheHeadLocks('{"packages": ["second"]}'), 'job-image');
        $this->clock->sleep(60);
        $store->of($this->history->work.'/.git', $this->givenTheHeadLocks('{"packages": ["first"]}'), 'job-image');
        $this->clock->sleep(60);

        $third = $store->of($this->history->work.'/.git', $this->givenTheHeadLocks('{"packages": ["third"]}'), 'job-image');

        self::assertEqualsCanonicalizing([basename((string) $first), basename((string) $third)], $this->entries());
        self::assertDirectoryDoesNotExist((string) $second);
    }

    public function testAnInstallOfTheSameLockFilesAlreadyRunningLeavesTheCheckToInstallAsBefore(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $store = $this->store($this->runnerInstalling());
        $this->givenAnotherCheckInstalls('{"packages": ["acme/lib"]}');

        $snapshot = $store->of($this->history->work.'/.git', $head, 'job-image');

        self::assertNull($snapshot);
        self::assertSame([], $this->ran);
    }

    public function testASnapshotAnotherCheckSavedJustBeforeTheLockWasTakenIsUsedWithoutASecondInstall(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $entry = $this->root.'/'.$this->keyOf('{"packages": ["acme/lib"]}');
        $this->locks = new SavingWhileLocking(new InMemoryStore(), static function () use ($entry): void {
            new Filesystem()->dumpFile($entry.'/vendor/acme/lib/Lib.php', "<?php\n");
        });
        $store = $this->store($this->runnerInstalling());

        $snapshot = $store->of($this->history->work.'/.git', $head, 'job-image');

        self::assertSame($entry, $snapshot);
        self::assertSame([], $this->ran);
    }

    public function testAStoreThatKeepsNoSnapshotIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store($this->runnerInstalling(), kept: 0);
    }

    public function testAStoreThatKeepsOneSnapshotIsAccepted(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');

        $snapshot = $this->store($this->runnerInstalling(), kept: 1)->of($this->history->work.'/.git', $head, 'job-image');

        self::assertNotNull($snapshot);
        self::assertCount(1, $this->entries());
    }

    private function givenTheHeadLocks(string $lock): string
    {
        $this->history->commitFile('composer.json', self::MANIFEST, 'Add the manifest');

        return $this->history->commitFile('composer.lock', $lock."\n", 'Lock the dependencies');
    }

    private function givenAnotherCheckInstalls(string $lock): void
    {
        $this->otherCheck = new LockFactory($this->locks)->createLock(DependencySnapshots::LOCK_PREFIX.$this->keyOf($lock), ttl: null);
        self::assertTrue($this->otherCheck->acquire());
    }

    private function keyOf(string $lock): string
    {
        return new DependencySource(new PhpGate()->dependencyInstall(), 'job-image', ['composer.json' => self::MANIFEST, 'composer.lock' => $lock."\n"])->key();
    }

    private function runnerInstalling(): JobRunner
    {
        return $this->runnerDoing(static function (string $kept): JobResult {
            new Filesystem()->dumpFile($kept.'/vendor/acme/lib/Lib.php', "<?php\n");

            return new JobResult(new JobEnd(JobCause::Exited, 0), new JobOutput(['install-result' => "installed\n", 'install.log' => "ok\n"]));
        });
    }

    /**
     * @param Closure(string): JobResult $install
     */
    private function runnerDoing(Closure $install): JobRunner
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use ($install): JobResult {
            $this->ran[] = $spec;
            $this->lockInputsSeen = $this->namesIn($spec->inputs[0]->hostPath);

            return $install((string) $spec->keptDirectory);
        });

        return $runner;
    }

    private function store(JobRunner $runner, ?Gate $gate = null, int $kept = 10): DependencySnapshots
    {
        $gate ??= new PhpGate();

        return new DependencySnapshots(
            $gate,
            new RepositoryGit(new HardenedGit()),
            new DependencyInstallJob($runner, $gate, new Filesystem(), self::SCRIPT),
            new LockFactory($this->locks),
            $this->clock,
            new Filesystem(),
            $this->root,
            $kept,
        );
    }

    /**
     * @return list<string>
     */
    private function entries(): array
    {
        return is_dir($this->root) ? $this->namesIn($this->root) : [];
    }

    /**
     * @return list<string>
     */
    private function namesIn(string $directory): array
    {
        $names = scandir($directory);

        return false === $names ? [] : array_values(array_diff($names, ['.', '..']));
    }
}
