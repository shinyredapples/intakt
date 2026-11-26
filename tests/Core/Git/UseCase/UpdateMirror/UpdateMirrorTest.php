<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\UpdateMirror;

use Intakt\Core\Disk\Contract\DiskFloor;
use Intakt\Core\Disk\Contract\DiskHeadroom;
use Intakt\Core\Disk\Contract\DiskHeld;
use Intakt\Core\Disk\Contract\NewWorkHeld;
use Intakt\Core\Disk\Domain\FloorCheck;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Contract\NetworkGitFailed;
use Intakt\Core\Git\Contract\WorkTreeInBareRepository;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Intakt\Core\Git\UseCase\UpdateMirror\MirrorNotUpdated;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Tests\Core\Disk\DiskWithRoom;
use Intakt\Tests\Core\Disk\FreeSpaceInMemory;
use Intakt\Tests\Core\Git\RecordingClock;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[CoversClass(UpdateMirror::class)]
#[CoversClass(Mirrors::class)]
#[CoversClass(ProjectNameRefused::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(NetworkGitFailed::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(MirrorNotUpdated::class)]
#[UsesClass(WorkTreeInBareRepository::class)]
#[UsesClass(FloorCheck::class)]
#[UsesClass(DiskHeld::class)]
#[UsesClass(NewWorkHeld::class)]
final class UpdateMirrorTest extends TestCase
{
    private string $mirrors;

    private TemporaryRepository $remote;

    private string $wrapper;

    private ?string $originalPath = null;

    private DiskHeadroom $disk;

    public function testTheFirstUpdateOfAProjectCreatesItsBareMirror(): void
    {
        $path = $this->update('alpha', $this->remote->path);

        self::assertSame($this->mirrors.'/alpha.git', $path);
        self::assertSame('true', $this->git($path, 'rev-parse', '--is-bare-repository'));
        self::assertSame($this->remote->objectAt('HEAD'), $this->git($path, 'rev-parse', 'refs/heads/main'));
    }

    public function testAFirstUpdateBelowAFloorClonesNothingAndClonesOnceSpaceIsBack(): void
    {
        $space = new FreeSpaceInMemory(10, 10 ** 6);
        $this->disk = new FloorCheck($space, '/unused', 1000, 100);

        try {
            $this->update('alpha', $this->remote->path);
            self::fail('The update was not held.');
        } catch (NewWorkHeld $held) {
            self::assertSame(DiskFloor::Bytes, $held->held->floor);
        }

        self::assertFileDoesNotExist($this->mirrors.'/alpha.git/HEAD');
        $space->leave(2000, 10 ** 6);
        self::assertSame($this->remote->objectAt('HEAD'), $this->git($this->update('alpha', $this->remote->path), 'rev-parse', 'refs/heads/main'));
    }

    public function testALaterUpdateBelowTheInodesFloorFetchesNothingIntoTheMirror(): void
    {
        $path = $this->update('alpha', $this->remote->path);
        $first = $this->remote->objectAt('HEAD');
        $this->remote->commitFile('b.txt', 'two');

        $this->disk = new FloorCheck(new FreeSpaceInMemory(10 ** 9, 5), '/unused', 1000, 100);

        try {
            $this->update('alpha', $this->remote->path);
            self::fail('The update was not held.');
        } catch (NewWorkHeld $held) {
            self::assertSame(DiskFloor::Inodes, $held->held->floor);
        }

        self::assertSame($first, $this->git($path, 'rev-parse', 'refs/heads/main'));
    }

    public function testALaterUpdateFetchesANewCommitIntoTheExistingMirror(): void
    {
        $path = $this->update('alpha', $this->remote->path);
        $inode = fileinode($path.'/objects');
        $second = $this->remote->commitFile('b.txt', 'two');

        $this->update('alpha', $this->remote->path);

        self::assertSame($second, $this->git($path, 'rev-parse', 'refs/heads/main'));
        self::assertSame($inode, fileinode($path.'/objects'));
    }

    public function testAnUpdatePrunesABranchTheRemoteDeleted(): void
    {
        $this->remote->checkoutNewBranch('topic');
        $this->remote->checkout('main');
        $path = $this->update('alpha', $this->remote->path);
        $this->git($this->remote->path, 'branch', '--delete', 'topic');

        $this->update('alpha', $this->remote->path);

        self::assertSame('', $this->git($path, 'branch', '--list', 'topic'));
    }

    public function testTwoProjectsGetTwoSeparateMirrors(): void
    {
        $first = $this->update('alpha', $this->remote->path);
        $second = $this->update('beta', $this->remote->path);

        self::assertNotSame($first, $second);
        self::assertDirectoryExists($first);
        self::assertDirectoryExists($second);
    }

    #[DataProvider('unsafeNames')]
    public function testAProjectNameThatIsNotASafePathSegmentIsRefused(string $name): void
    {
        $this->expectException(ProjectNameRefused::class);

        try {
            $this->update($name, $this->remote->path);
        } finally {
            self::assertDirectoryDoesNotExist($this->mirrors);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeNames(): iterable
    {
        yield 'a parent folder' => ['..'];
        yield 'a separator' => ['a/b'];
        yield 'a leading dot' => ['.hidden'];
        yield 'an option' => ['--upload-pack=x'];
        yield 'empty' => [''];
        yield 'a trailing newline' => ["alpha\n"];
    }

    public function testAnEmptyMirrorFolderInsideAWorkTreeBecomesAMirrorAndLeavesTheWorkTreeAlone(): void
    {
        $install = new TemporaryRepository();
        $install->commitFile('install.txt', 'install');
        $this->git($install->path, 'update-ref', 'refs/remotes/origin/kept', 'HEAD');
        $this->mirrors = $install->path.'/var/mirrors';
        mkdir($this->mirrors.'/alpha.git', recursive: true);

        try {
            $path = $this->update('alpha', $this->remote->path);

            self::assertSame($this->remote->objectAt('HEAD'), $this->git($path, 'rev-parse', 'refs/heads/main'));
            self::assertSame('refs/remotes/origin/kept', $this->git($install->path, 'for-each-ref', '--format=%(refname)', 'refs/remotes/'));
        } finally {
            $install->remove();
        }
    }

    public function testAFailedUpdateOfAnEmptyMirrorFolderInsideAWorkTreeLeavesTheWorkTreeAlone(): void
    {
        $install = new TemporaryRepository();
        $install->commitFile('install.txt', 'install');
        $this->git($install->path, 'update-ref', 'refs/remotes/origin/kept', 'HEAD');
        $this->mirrors = $install->path.'/var/mirrors';
        mkdir($this->mirrors.'/alpha.git', recursive: true);

        try {
            $this->update('alpha', $install->path.'/missing');
            self::fail('An update from a missing remote must fail.');
        } catch (NetworkGitFailed) {
            self::assertSame('refs/remotes/origin/kept', $this->git($install->path, 'for-each-ref', '--format=%(refname)', 'refs/remotes/'));
        } finally {
            $install->remove();
        }
    }

    public function testABareRepositoryLeftWithoutRefsIsFilledFromTheRemote(): void
    {
        mkdir($this->mirrors);
        $this->git($this->mirrors, 'init', '--bare', '--quiet', 'alpha.git');

        $path = $this->update('alpha', $this->remote->path);

        self::assertSame($this->remote->objectAt('HEAD'), $this->git($path, 'rev-parse', 'refs/heads/main'));
    }

    public function testARepositoryWhoseConfigNamesAWorkTreeIsNoMirrorAndIsReplacedByOne(): void
    {
        mkdir($this->mirrors);
        $this->git($this->mirrors, 'init', '--bare', '--quiet', 'alpha.git');
        $this->git($this->mirrors.'/alpha.git', 'config', 'core.bare', 'false');
        $this->git($this->mirrors.'/alpha.git', 'config', 'core.worktree', $this->mirrors);

        $path = $this->update('alpha', $this->remote->path);

        self::assertSame('true', $this->git($path, 'config', 'core.bare'));
        self::assertSame('', $this->git($path, 'config', '--default', '', 'core.worktree'));
        self::assertSame($this->remote->objectAt('HEAD'), $this->git($path, 'rev-parse', 'refs/heads/main'));
    }

    public function testACheckThatIsKilledBySignalNeverReplacesAValidMirror(): void
    {
        $path = $this->update('alpha', $this->remote->path);
        file_put_contents($path.'/description', 'Keep this existing mirror');
        $this->beforeTheFirstGitCallWith('--is-bare-repository', 'kill -TERM $$');

        try {
            $this->update('alpha', $this->remote->path);
            self::fail('A check that was killed gives no answer and must stop the update.');
        } catch (RuntimeException) {
            self::assertSame('Keep this existing mirror', file_get_contents($path.'/description'));
        }
    }

    public function testTheMirrorsHeadFollowsTheRemotesHead(): void
    {
        $path = $this->update('alpha', $this->remote->path);

        self::assertSame('refs/heads/main', $this->git($path, 'symbolic-ref', 'HEAD'));
    }

    public function testTheMirrorOfASha256RemoteResolvesHeadToTheRemotesCommit(): void
    {
        $remote = new TemporaryRepository('sha256');

        try {
            $commit = $remote->commitFile('a.txt', 'one');

            $path = $this->update('alpha', $remote->path);

            self::assertSame($commit, $this->git($path, 'rev-parse', '--verify', 'HEAD'));
        } finally {
            $remote->remove();
        }
    }

    public function testTheHeadOfAMirrorOfARemoteWithADetachedHeadResolvesToTheDetachedCommit(): void
    {
        $first = $this->remote->objectAt('HEAD');
        $this->remote->commitFile('b.txt', 'two');
        $this->remote->checkoutDetached($first);

        $path = $this->update('alpha', $this->remote->path);

        self::assertSame($first, $this->git($path, 'rev-parse', '--verify', 'HEAD'));
    }

    public function testAnExistingMirrorFollowsARemoteWhoseHeadBecameDetached(): void
    {
        $path = $this->update('alpha', $this->remote->path);
        $first = $this->remote->objectAt('HEAD');
        $this->remote->commitFile('b.txt', 'two');
        $this->remote->checkoutDetached($first);

        $this->update('alpha', $this->remote->path);

        self::assertSame($first, $this->git($path, 'rev-parse', '--verify', 'HEAD'));
    }

    public function testAnExistingMirrorFollowsARemoteWhoseDetachedHeadIsACommitOnNoBranch(): void
    {
        $path = $this->update('alpha', $this->remote->path);
        $this->remote->checkoutDetached('HEAD');
        $orphan = $this->remote->commitFile('b.txt', 'on no branch');

        $this->update('alpha', $this->remote->path);

        self::assertSame($orphan, $this->git($path, 'rev-parse', '--verify', 'HEAD'));
    }

    public function testThePublishedMirrorNamesNoRemoteAndKeepsNoCredentialOfTheAddressItWasClonedFrom(): void
    {
        $credential = bin2hex(random_bytes(8));

        $path = $this->update('alpha', 'file://intakt:'.$credential.'@'.$this->remote->path);

        $config = (string) file_get_contents($path.'/config');
        self::assertStringNotContainsString($credential, $config);
        self::assertStringNotContainsString('[remote', $config);
        self::assertSame($this->remote->objectAt('HEAD'), $this->git($path, 'rev-parse', 'refs/heads/main'));
    }

    public function testASecondUpdateThroughACredentialedFileAddressLeavesNoFetchHeadInTheMirror(): void
    {
        $address = 'file://intakt:'.bin2hex(random_bytes(8)).'@'.$this->remote->path;
        $path = $this->update('alpha', $address);
        $this->remote->commitFile('b.txt', 'two');

        $this->update('alpha', $address);

        self::assertFileDoesNotExist($path.'/FETCH_HEAD');
    }

    public function testFollowingADetachedHeadOnNoBranchLeavesNoFetchHeadInTheMirror(): void
    {
        $path = $this->update('alpha', $this->remote->path);
        $this->remote->checkoutDetached('HEAD');
        $this->remote->commitFile('b.txt', 'on no branch');

        $this->update('alpha', $this->remote->path);

        self::assertFileDoesNotExist($path.'/FETCH_HEAD');
    }

    public function testARelativeRemotePathCountsFromTheMirrorsFolderOnEveryUpdate(): void
    {
        // Resolved against the mirrors folder, not the mirror, so the first update (a clone) and the second (a fetch) name the same repository.
        $relative = '../'.basename($this->remote->path);
        $path = $this->update('alpha', $relative);
        $second = $this->remote->commitFile('b.txt', 'two');

        self::assertSame($path, $this->update('alpha', $relative));
        self::assertSame($second, $this->git($path, 'rev-parse', 'refs/heads/main'));
    }

    public function testARemoteWithAHostBeforeThePathIsNotReadAsARelativePath(): void
    {
        try {
            $this->update('alpha', 'localhost:missing.git');
            self::fail('An ssh address must be refused by the transport allowlist.');
        } catch (NetworkGitFailed $failed) {
            self::assertStringContainsString("'ssh'", $failed->getMessage());
            self::assertStringNotContainsString($this->mirrors, $failed->getMessage());
        }
    }

    public function testAFailedFirstUpdateLeavesNoFolderBehind(): void
    {
        try {
            $this->update('alpha', $this->mirrors.'-missing');
            self::fail('An update from a missing remote must fail.');
        } catch (NetworkGitFailed) {
            self::assertSame(['.', '..'], scandir($this->mirrors));
        }
    }

    public function testAnUpdateThatAnotherUpdateOvertakesDuringItsRetryDelaySucceeds(): void
    {
        $remote = $this->mirrors.'-late-remote';
        $overtaken = new RecordingClock(function (int $sleeps) use ($remote): void {
            $this->git(sys_get_temp_dir(), 'init', '--bare', '--quiet', $remote);
            $this->update('alpha', $remote);
        });

        try {
            $path = $this->update('alpha', $remote, $overtaken);

            self::assertSame([10], $overtaken->sleeps);
            self::assertSame('true', $this->git($path, 'rev-parse', '--is-bare-repository'));
        } finally {
            new Filesystem()->remove($remote);
        }
    }

    public function testAnOvertakenUpdateLeavesThePublishedMirrorAtLeastAsFreshAsItsOwnFetch(): void
    {
        $remote = $this->mirrors.'-late-remote';
        $newer = '';
        // Because the update that wins can have fetched an older state than the overtaken one: the remote gains a commit between the inner update and the retry.
        $overtaken = new RecordingClock(function () use ($remote, &$newer): void {
            $this->git(sys_get_temp_dir(), 'clone', '--quiet', '--bare', $this->remote->path, $remote);
            $this->update('alpha', $remote);
            $newer = $this->remote->commitFile('b.txt', 'two');
            $this->git($remote, 'fetch', '--quiet', $this->remote->path, '+refs/heads/main:refs/heads/main');
        });

        try {
            $path = $this->update('alpha', $remote, $overtaken);

            self::assertSame([10], $overtaken->sleeps);
            self::assertSame($newer, $this->git($path, 'rev-parse', 'refs/heads/main'));
        } finally {
            new Filesystem()->remove($remote);
        }
    }

    public function testTheFirstUpdateOfASha256RemoteThroughAFileAddressResolvesHeadToTheRemotesCommit(): void
    {
        $remote = new TemporaryRepository('sha256');

        try {
            $commit = $remote->commitFile('a.txt', 'one');

            $path = $this->update('alpha', 'file://'.$remote->path);

            self::assertSame($commit, $this->git($path, 'rev-parse', '--verify', 'HEAD'));
        } finally {
            $remote->remove();
        }
    }

    public function testASecondUpdateOfASha256RemoteThroughTheSameFileAddressFetchesANewCommit(): void
    {
        $remote = new TemporaryRepository('sha256');

        try {
            $remote->commitFile('a.txt', 'one');
            $path = $this->update('alpha', 'file://'.$remote->path);
            $second = $remote->commitFile('b.txt', 'two');

            $this->update('alpha', 'file://'.$remote->path);

            self::assertSame($second, $this->git($path, 'rev-parse', '--verify', 'HEAD'));
        } finally {
            $remote->remove();
        }
    }

    public function testALocalStepThatFailsInAnExistingMirrorThrowsMirrorNotUpdated(): void
    {
        $path = $this->update('alpha', $this->remote->path);
        file_put_contents($path.'/HEAD.lock', '');

        $this->expectException(MirrorNotUpdated::class);

        $this->update('alpha', $this->remote->path);
    }

    public function testAnExistingMirrorFollowsARemoteThatSwitchesToANewBranchWhenTheMirrorAsksForItsHead(): void
    {
        $path = $this->update('alpha', $this->remote->path);
        $this->remote->checkoutDetached('HEAD');
        $next = $this->remote->commitFile('b.txt', 'on the new branch');
        $this->remote->checkout('main');
        $remote = escapeshellarg('--git-dir='.$this->remote->path.'/.git');
        $this->beforeTheFirstGitCallWith('ls-remote', sprintf('"$REAL" %1$s update-ref refs/heads/next %2$s && "$REAL" %1$s symbolic-ref HEAD refs/heads/next', $remote, $next));

        $this->update('alpha', $this->remote->path);

        self::assertSame($next, $this->git($path, 'rev-parse', '--verify', 'HEAD'));
    }

    public function testAnUpdateWhoseRemoteDeletesTheBranchItsHeadNamedBeforeTheFetchThrowsMirrorNotUpdated(): void
    {
        $this->remote->checkoutNewBranch('topic');
        $this->update('alpha', $this->remote->path);
        $this->beforeTheFirstGitCallWith('fetch', sprintf('"$REAL" %s update-ref -d refs/heads/topic', escapeshellarg('--git-dir='.$this->remote->path.'/.git')));

        try {
            $this->update('alpha', $this->remote->path);
            self::fail('An update whose HEAD names a branch the fetch did not bring must fail.');
        } catch (MirrorNotUpdated $failed) {
            self::assertStringContainsString('HEAD names refs/heads/topic', $failed->getMessage());
        }
    }

    protected function setUp(): void
    {
        $this->mirrors = sys_get_temp_dir().'/intakt-mirrors-'.bin2hex(random_bytes(6));
        $this->wrapper = $this->mirrors.'-bin';
        $this->disk = new DiskWithRoom();
        $this->remote = new TemporaryRepository();
        $this->remote->commitFile('a.txt', 'one');
    }

    protected function tearDown(): void
    {
        if (null !== $this->originalPath) {
            $this->withPath($this->originalPath);
        }

        new Filesystem()->remove([$this->mirrors, $this->wrapper]);
        $this->remote->remove();
    }

    private function beforeTheFirstGitCallWith(string $command, string $action): void
    {
        // A git first on PATH runs the action once, right before the call, so the remote changes at that exact moment instead of by chance.
        $real = new ExecutableFinder()->find('git') ?? self::fail('git is not on PATH.');
        $marker = $this->wrapper.'/ran';
        new Filesystem()->dumpFile($this->wrapper.'/git', sprintf(
            "#!/bin/sh\nREAL=%s\nfor argument in \"\$@\"; do\n  if [ \"\$argument\" = %s ] && [ ! -e %s ]; then\n    : > %3\$s\n    %s || exit 99\n  fi\ndone\nexec \"\$REAL\" \"\$@\"\n",
            escapeshellarg($real),
            escapeshellarg($command),
            escapeshellarg($marker),
            $action,
        ));
        chmod($this->wrapper.'/git', 0o755);
        $this->originalPath = (string) getenv('PATH');
        $this->withPath($this->wrapper.':'.$this->originalPath);
    }

    private function withPath(string $path): void
    {
        putenv('PATH='.$path);
        // Because Symfony Process gives a child $_ENV ahead of the process environment.
        if (array_key_exists('PATH', $_ENV)) {
            $_ENV['PATH'] = $path;
        }
    }

    private function update(string $project, string $remote, ?RecordingClock $clock = null): string
    {
        $network = new NetworkGit($clock ?? new RecordingClock(), new NullLogger());

        return new UpdateMirror(new HardenedGit(), $network, new Filesystem(), new LockFactory(new InMemoryStore()), new Mirrors($this->mirrors), $this->disk)->update($project, $remote);
    }

    private function git(string $directory, string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], $directory, ['GIT_DIR' => false, 'GIT_WORK_TREE' => false]);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
