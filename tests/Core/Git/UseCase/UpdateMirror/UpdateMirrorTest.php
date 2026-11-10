<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\UpdateMirror;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Contract\NetworkGitFailed;
use Intakt\Core\Git\UseCase\UpdateMirror\MirrorNotUpdated;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Tests\Core\Git\RecordingClock;
use Intakt\Tests\Core\Git\TemporaryRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\Process;

#[CoversClass(UpdateMirror::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(NetworkGitFailed::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(MirrorNotUpdated::class)]
final class UpdateMirrorTest extends TestCase
{
    private string $mirrors;

    private TemporaryRepository $remote;

    protected function setUp(): void
    {
        $this->mirrors = sys_get_temp_dir().'/intakt-mirrors-'.bin2hex(random_bytes(6));
        $this->remote = new TemporaryRepository();
        $this->remote->commitFile('a.txt', 'one');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->mirrors);
        $this->remote->remove();
    }

    public function testTheFirstUpdateOfAProjectCreatesItsBareMirror(): void
    {
        $path = $this->update('alpha', $this->remote->path);

        self::assertSame($this->mirrors.'/alpha.git', $path);
        self::assertSame('true', $this->git($path, 'rev-parse', '--is-bare-repository'));
        self::assertSame($this->remote->objectAt('HEAD'), $this->git($path, 'rev-parse', 'refs/heads/main'));
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
        $this->expectException(InvalidArgumentException::class);

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

    private function update(string $project, string $remote, ?RecordingClock $clock = null): string
    {
        $network = new NetworkGit($clock ?? new RecordingClock(), new NullLogger());

        return new UpdateMirror(new HardenedGit(), $network, new Filesystem(), new LockFactory(new InMemoryStore()), $this->mirrors)->update($project, $remote);
    }

    private function git(string $directory, string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], $directory, ['GIT_DIR' => false, 'GIT_WORK_TREE' => false]);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
