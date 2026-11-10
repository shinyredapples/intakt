<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\UpdateMirror;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
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
use Symfony\Component\Process\Process;

#[CoversClass(UpdateMirror::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(GitCall::class)]
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
        $inode = fileinode($path.'/HEAD');
        $second = $this->remote->commitFile('b.txt', 'two');

        $this->update('alpha', $this->remote->path);

        self::assertSame($second, $this->git($path, 'rev-parse', 'refs/heads/main'));
        self::assertSame($inode, fileinode($path.'/HEAD'));
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
    }

    private function update(string $project, string $remote): string
    {
        $network = new NetworkGit(new RecordingClock(), new NullLogger());

        return new UpdateMirror(new HardenedGit(), $network, new Filesystem(), $this->mirrors)->update($project, $remote);
    }

    private function git(string $directory, string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], $directory, ['GIT_DIR' => false, 'GIT_WORK_TREE' => false]);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
