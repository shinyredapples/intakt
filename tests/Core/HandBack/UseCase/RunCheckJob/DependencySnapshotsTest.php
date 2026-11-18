<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\RunCheckJob;

use Closure;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\HandBack\UseCase\CheckHandBack\RepositoryGit;
use Intakt\Core\HandBack\UseCase\RunCheckJob\DependencyInstallJob;
use Intakt\Core\HandBack\UseCase\RunCheckJob\DependencySnapshot;
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
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\SharedLockStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(DependencySnapshots::class)]
#[CoversClass(DependencySnapshot::class)]
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

    private SharedLockStoreInterface $locks;

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

        $snapshot = $this->store($this->runnerInstalling())->of($this->repository(), $head, 'job-image');

        self::assertInstanceOf(DependencySnapshot::class, $snapshot);
        self::assertStringStartsWith($this->root.'/', $snapshot->path);
        self::assertSame('vendor', $snapshot->directory);
        self::assertFileExists($snapshot->path.'/vendor/acme/lib/Lib.php');
        self::assertCount(1, $this->ran);
        self::assertSame(['composer.json', 'composer.lock'], $this->lockInputsSeen);
        $install = $this->ran[0];
        self::assertCount(1, $install->inputs);
        self::assertSame('/input/lock', $install->inputs[0]->containerPath);
        self::assertSame(['/input/lock', '/kept', 'vendor', '--', 'composer install --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader'], array_slice($install->command, 4));
        self::assertSame((string) file_get_contents(self::SCRIPT), $install->command[2]);
        self::assertSame('job-image', $install->image);
        self::assertSame(['repo.packagist.org', 'api.github.com', 'codeload.github.com'], $install->egress->hosts);
        self::assertDirectoryDoesNotExist($snapshot->path.'.staging');
    }

    public function testAHitReusesTheSnapshotAndRunsNoInstall(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $store = $this->store($this->runnerInstalling());
        $first = $this->pathAt($store, $head);

        $second = $this->pathAt($store, $this->history->commit('Change only the code'));

        self::assertNotNull($first);
        self::assertSame($first, $second);
        self::assertCount(1, $this->ran);
    }

    public function testAChangedLockFileMissesTheSnapshotOfTheOldOne(): void
    {
        $store = $this->store($this->runnerInstalling());
        $old = $this->pathAt($store, $this->givenTheHeadLocks('{"packages": ["acme/lib"]}'));

        $new = $this->pathAt($store, $this->givenTheHeadLocks('{"packages": ["acme/lib", "acme/other"]}'));

        self::assertNotNull($new);
        self::assertNotSame($old, $new);
        self::assertCount(2, $this->ran);
    }

    public function testAnotherJobImageMissesTheSnapshotBecauseItFixesThePhpVersion(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $store = $this->store($this->runnerInstalling());
        $old = $this->pathAt($store, $head, 'sha256:'.str_repeat('1', 64));

        $new = $this->pathAt($store, $head, 'sha256:'.str_repeat('2', 64));

        self::assertNotSame($old, $new);
        self::assertCount(2, $this->ran);
    }

    public function testAHeadWithoutALockFileGetsNoSnapshotAndNoInstall(): void
    {
        $this->history->commitFile('composer.json', "{}\n", 'Add the manifest alone');

        $snapshot = $this->pathAt($this->store($this->runnerInstalling()), $this->history->commit('Build work'));

        self::assertNull($snapshot);
        self::assertSame([], $this->ran);
    }

    public function testALanguageWithoutSnapshotSupportGetsNoSnapshotAndNoInstall(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $gate = self::createStub(Gate::class);
        $gate->method('dependencyInstall')->willReturn(null);

        $snapshot = $this->pathAt($this->store($this->runnerInstalling(), $gate), $head);

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

        $snapshot = $this->pathAt($this->store($runner), $head);

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

        self::assertNull($this->pathAt($this->store($runner), $head));
        self::assertSame([], $this->entries());
    }

    public function testAnInstallWhoseOutputTheRunnerRefusesSavesNothing(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willThrowException(JobOutputRefused::tooMany(1000));

        self::assertNull($this->pathAt($this->store($runner), $head));
        self::assertSame([], $this->entries());
    }

    public function testAnInstallThatThrowsAfterWritingLeavesNoStagingFolderAndNoLock(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $runner = $this->runnerDoing(static function (string $kept): JobResult {
            new Filesystem()->dumpFile($kept.'/vendor/acme/lib/Lib.php', "<?php\n");

            throw new RuntimeException('The engine went away.');
        });
        $store = $this->store($runner);

        try {
            $this->pathAt($store, $head);
            self::fail('The engine failure was swallowed.');
        } catch (RuntimeException $failure) {
            self::assertSame('The engine went away.', $failure->getMessage());
        }

        self::assertSame([], $this->entries());
        self::assertNotNull($this->pathAt($this->store($this->runnerInstalling()), $head));
    }

    public function testAStrayFileAtTheEntryIsReplacedByTheSnapshotInsteadOfBreakingEveryLaterCheck(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        new Filesystem()->mkdir($this->root);
        touch($this->root.'/'.$this->keyOf('{"packages": ["acme/lib"]}'), 1);

        $snapshot = $this->pathAt($this->store($this->runnerInstalling()), $head);

        self::assertNotNull($snapshot);
        self::assertFileExists($snapshot.'/vendor/acme/lib/Lib.php');
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

        $snapshot = (string) $this->pathAt($this->store($runner), $head);

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

        $snapshot = (string) $this->pathAt($this->store($runner), $head);

        self::assertTrue(is_link($snapshot.'/vendor/acme/link'));
        clearstatcache();
        self::assertSame(0o666, fileperms($outside) & 0o777);
    }

    public function testTheStoreKeepsOnlyTheMostRecentlyUsedSnapshots(): void
    {
        $store = $this->store($this->runnerInstalling(), kept: 2);
        $first = $this->pathAt($store, $this->givenTheHeadLocks('{"packages": ["first"]}'));
        $this->clock->sleep(60);
        $second = $this->pathAt($store, $this->givenTheHeadLocks('{"packages": ["second"]}'));
        $this->clock->sleep(60);
        $this->pathAt($store, $this->givenTheHeadLocks('{"packages": ["first"]}'));
        $this->clock->sleep(60);

        $third = $this->pathAt($store, $this->givenTheHeadLocks('{"packages": ["third"]}'));

        self::assertEqualsCanonicalizing([basename((string) $first), basename((string) $third)], $this->entries());
        self::assertDirectoryDoesNotExist((string) $second);
    }

    public function testASaveNeverEvictsTheSnapshotItJustSavedWhenAllWereUsedInTheSameSecond(): void
    {
        $store = $this->store($this->runnerInstalling(), kept: 1);
        $locks = ['{"packages":[]}', '{"packages": []}'];
        usort($locks, fn (string $a, string $b): int => strcmp($this->keyOf($a), $this->keyOf($b)));

        foreach ($locks as $lock) {
            $snapshot = $this->pathAt($store, $this->givenTheHeadLocks($lock));

            self::assertNotNull($snapshot);
            self::assertDirectoryExists($snapshot);
        }

        self::assertSame([$this->keyOf($locks[1])], $this->entries());
    }

    public function testASnapshotACheckStillHoldsIsNotEvictedUntilItIsReleased(): void
    {
        $store = $this->store($this->runnerInstalling(), kept: 1);
        $held = $store->of($this->repository(), $this->givenTheHeadLocks('{"packages": ["held"]}'), 'job-image');
        self::assertInstanceOf(DependencySnapshot::class, $held);
        $this->clock->sleep(60);

        $this->pathAt($store, $this->givenTheHeadLocks('{"packages": ["second"]}'));

        self::assertDirectoryExists($held->path);
        $held->release();
        $this->clock->sleep(60);
        $this->pathAt($store, $this->givenTheHeadLocks('{"packages": ["third"]}'));
        self::assertDirectoryDoesNotExist($held->path);
    }

    public function testASnapshotBeingEvictedIsNotHandedToACheck(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $store = $this->store($this->runnerInstalling());
        $this->pathAt($store, $head);
        $this->givenAnotherCheckHoldsTheKeyExclusively('{"packages": ["acme/lib"]}');

        $snapshot = $this->pathAt($store, $head);

        self::assertNull($snapshot);
        self::assertCount(1, $this->ran);
    }

    public function testAStagingFolderACrashLeftIsSweptByTheNextSave(): void
    {
        $left = $this->root.'/'.$this->keyOf('{"packages": ["crashed"]}').'.staging';
        new Filesystem()->dumpFile($left.'/kept/vendor/Half.php', "<?php\n");

        $this->pathAt($this->store($this->runnerInstalling()), $this->givenTheHeadLocks('{"packages": ["acme/lib"]}'));

        self::assertDirectoryDoesNotExist($left);
    }

    public function testAStagingFolderOfAnInstallStillRunningIsKept(): void
    {
        $running = $this->root.'/'.$this->keyOf('{"packages": ["running"]}').'.staging';
        new Filesystem()->dumpFile($running.'/kept/vendor/Half.php', "<?php\n");
        $this->givenAnotherCheckHoldsTheKeyExclusively('{"packages": ["running"]}');

        $this->pathAt($this->store($this->runnerInstalling()), $this->givenTheHeadLocks('{"packages": ["acme/lib"]}'));

        self::assertDirectoryExists($running);
    }

    public function testAnInstallOfTheSameLockFilesAlreadyRunningLeavesTheCheckToInstallAsBefore(): void
    {
        $head = $this->givenTheHeadLocks('{"packages": ["acme/lib"]}');
        $store = $this->store($this->runnerInstalling());
        $this->givenAnotherCheckHoldsTheKeyExclusively('{"packages": ["acme/lib"]}');

        $snapshot = $this->pathAt($store, $head);

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

        $snapshot = $this->pathAt($store, $head);

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

        $snapshot = $this->pathAt($this->store($this->runnerInstalling(), kept: 1), $head);

        self::assertNotNull($snapshot);
        self::assertCount(1, $this->entries());
    }

    private function repository(): string
    {
        return $this->history->work.'/.git';
    }

    // Released at once, as a check job that ended would, so the entry is free for eviction again.
    private function pathAt(DependencySnapshots $store, string $head, string $image = 'job-image'): ?string
    {
        $snapshot = $store->of($this->repository(), $head, $image);
        $snapshot?->release();

        return $snapshot?->path;
    }

    private function givenTheHeadLocks(string $lock): string
    {
        $this->history->commitFile('composer.json', self::MANIFEST, 'Add the manifest');

        return $this->history->commitFile('composer.lock', $lock."\n", 'Lock the dependencies');
    }

    private function givenAnotherCheckHoldsTheKeyExclusively(string $lock): void
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
