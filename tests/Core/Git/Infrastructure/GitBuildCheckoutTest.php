<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\Infrastructure;

use Intakt\Tests\Core\Disk\DiskWithRoom;

use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Intakt\Core\Git\Domain\TaskBranch;
use Intakt\Core\Git\Infrastructure\GitBuildCheckout;
use Intakt\Core\Git\UseCase\MakeBuildClone\BranchNameRefused;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildClone;
use Intakt\Core\Git\UseCase\TakeCheckedHead\TakeCheckedHead;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Tests\Core\Git\PregMatchThatThrows;
use Intakt\Tests\Core\Git\RecordingClock;
use Intakt\Tests\Core\Git\TemporaryRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(GitBuildCheckout::class)]
#[UsesClass(BranchHead::class)]
#[UsesClass(BuildCheckoutFailed::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(ProjectNameRefused::class)]
#[UsesClass(TaskBranch::class)]
#[UsesClass(BranchNameRefused::class)]
#[UsesClass(MakeBuildClone::class)]
#[UsesClass(TakeCheckedHead::class)]
#[UsesClass(UpdateMirror::class)]
final class GitBuildCheckoutTest extends TestCase
{
    private string $mirrors;

    private TemporaryRepository $remote;

    #[RunInSeparateProcess]
    public function testAnUnexpectedInvalidArgumentExceptionPassesThroughTheMirrorPath(): void
    {
        PregMatchThatThrows::define();

        $this->expectExceptionObject(new InvalidArgumentException('unexpected'));

        $this->checkout(new Filesystem())->mirrorOf('alpha');
    }

    public function testAProjectNameThatIsNoFolderNameFailsTheMirrorPathWithItsReason(): void
    {
        $this->expectException(BuildCheckoutFailed::class);
        $this->expectExceptionMessageIsOrContains('"../alpha" is not a project name that can be a folder name');

        $this->checkout(new Filesystem())->mirrorOf('../alpha');
    }

    public function testAProjectNameThatIsNoFolderNameFailsTheMirrorUpdateWithItsReason(): void
    {
        $this->expectException(BuildCheckoutFailed::class);
        $this->expectExceptionMessageIsOrContains('"../alpha" is not a project name that can be a folder name');

        $this->checkout(new Filesystem())->updateMirror('../alpha', $this->remote->path);
    }

    public function testATaskBranchThatIsMainFailsTheCloneWithItsReason(): void
    {
        $this->checkout(new Filesystem())->updateMirror('alpha', $this->remote->path);

        $this->expectException(BuildCheckoutFailed::class);
        $this->expectExceptionMessageIsOrContains('the task\'s branch "main" is main itself');

        $this->checkout(new Filesystem())->makeClone('alpha', 'main', $this->mirrors.'-clone');
    }

    public function testAnUnexpectedInvalidArgumentExceptionPassesThroughTheMirrorUpdate(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('unexpected'));

        $this->checkout($this->filesystemThatThrows())->updateMirror('alpha', $this->remote->path);
    }

    public function testAnUnexpectedInvalidArgumentExceptionPassesThroughTheClone(): void
    {
        $this->checkout(new Filesystem())->updateMirror('alpha', $this->remote->path);

        $this->expectExceptionObject(new InvalidArgumentException('unexpected'));

        $this->checkout($this->filesystemThatThrows())->makeClone('alpha', 'feat/task', $this->mirrors.'-clone');
    }

    public function testAnUnexpectedInvalidArgumentExceptionPassesThroughTakingTheCheckedHead(): void
    {
        stream_wrapper_register(StatThatThrows::PROTOCOL, StatThatThrows::class);

        $this->expectExceptionObject(new InvalidArgumentException('unexpected'));

        $this->checkout(new Filesystem())->takeCheckedHead('alpha', new BranchHead('feat/task', $this->remote->objectAt('main')), StatThatThrows::PROTOCOL.'://clone');
    }

    protected function setUp(): void
    {
        $this->mirrors = sys_get_temp_dir().'/intakt-mirrors-'.bin2hex(random_bytes(6));
        $this->remote = new TemporaryRepository();
        $this->remote->commitFile('a.txt', 'one');
    }

    protected function tearDown(): void
    {
        if (in_array(StatThatThrows::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(StatThatThrows::PROTOCOL);
        }

        new Filesystem()->remove([$this->mirrors, $this->mirrors.'-clone']);
        $this->remote->remove();
    }

    private function filesystemThatThrows(): Filesystem
    {
        $filesystem = self::createStub(Filesystem::class);
        $filesystem->method('mkdir')->willThrowException(new InvalidArgumentException('unexpected'));

        return $filesystem;
    }

    private function checkout(Filesystem $filesystem): GitBuildCheckout
    {
        $mirrors = new Mirrors($this->mirrors);
        $network = new NetworkGit(new RecordingClock(), new NullLogger());

        return new GitBuildCheckout(
            new UpdateMirror(new HardenedGit(), $network, $filesystem, new LockFactory(new InMemoryStore()), $mirrors),
            new MakeBuildClone(new HardenedGit(), $filesystem, $mirrors, new DiskWithRoom()),
            $mirrors,
            new TakeCheckedHead(new HardenedGit(), $mirrors),
        );
    }
}
