<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\ListProtectedChanges;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\ProtectedChanges;
use Intakt\Core\Git\Domain\ChangedPath;
use Intakt\Core\Git\Domain\ChangeNotRead;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Intakt\Core\Git\Domain\ProtectedPathRefused;
use Intakt\Core\Git\Domain\ProtectedPaths;
use Intakt\Core\Git\UseCase\ListProtectedChanges\ChangedPaths;
use Intakt\Core\Git\UseCase\ListProtectedChanges\CommitNameRefused;
use Intakt\Core\Git\UseCase\ListProtectedChanges\ListProtectedChanges;
use Intakt\Core\Git\UseCase\ListProtectedChanges\ProtectedHeadsOfMirrors;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ProtectedHeadsOfMirrors::class)]
#[UsesClass(ListProtectedChanges::class)]
#[UsesClass(ChangedPaths::class)]
#[UsesClass(ChangedPath::class)]
#[UsesClass(ChangeNotRead::class)]
#[UsesClass(ProtectedChanges::class)]
#[UsesClass(ProtectedPaths::class)]
#[UsesClass(ProtectedPathRefused::class)]
#[UsesClass(CommitNameRefused::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(ProjectNameRefused::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
final class ProtectedHeadsOfMirrorsTest extends TestCase
{
    private string $mirrors;

    private TemporaryRepository $repository;

    protected function setUp(): void
    {
        $this->mirrors = sys_get_temp_dir().'/intakt-mirrors-'.bin2hex(random_bytes(6));
        mkdir($this->mirrors);
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('bin/check', 'one');
        $this->repository->checkoutNewBranch('feat/side');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->mirrors);
        $this->repository->remove();
    }

    public function testAHeadIsCheckedAgainstTheListOfItsOwnProject(): void
    {
        $head = $this->repository->commitFile('bin/check', 'two');

        $changes = $this->heads(['alpha' => ['bin/check'], 'beta' => []])->of('alpha', $head);

        self::assertSame(['bin/check'], $changes->paths);
    }

    public function testAHeadOfAProjectWithoutAListTouchesNoProtectedPath(): void
    {
        $head = $this->repository->commitFile('bin/check', 'two');

        self::assertFalse($this->heads(['beta' => ['bin/check']])->of('alpha', $head)->touchesProtectedPaths());
    }

    public function testAHeadThatIsNoFullCommitNameCountsAsProtected(): void
    {
        $changes = $this->heads([])->of('alpha', 'feat/side');

        self::assertTrue($changes->touchesProtectedPaths());
        self::assertStringContainsString('"feat/side" is not a full commit name', (string) $changes->unreadable);
    }

    public function testAProjectNameThatIsNoFolderNameCountsAsProtected(): void
    {
        $changes = $this->heads([])->of('../alpha', $this->repository->objectAt('HEAD'));

        self::assertTrue($changes->touchesProtectedPaths());
        self::assertStringContainsString('is not a project name that can be a folder name', (string) $changes->unreadable);
    }

    public function testAProtectedPathThatIsNoPathOfTheRepositoryCountsAsProtected(): void
    {
        $changes = $this->heads(['alpha' => ['../outside']])->of('alpha', $this->repository->objectAt('HEAD'));

        self::assertTrue($changes->touchesProtectedPaths());
        self::assertStringContainsString('"../outside" is not a protected path', (string) $changes->unreadable);
    }

    public function testTheCheckedAnswerStillThrowsARefusalForTheCommand(): void
    {
        $this->expectException(CommitNameRefused::class);

        $_ = $this->heads([])->checked('alpha', 'feat/side');
    }

    /**
     * @param array<string, list<string>> $protectedPaths
     */
    private function heads(array $protectedPaths): ProtectedHeadsOfMirrors
    {
        $this->repository->checkout('main');
        symlink($this->repository->path.'/.git', $this->mirrors.'/alpha.git');

        return new ProtectedHeadsOfMirrors(new ListProtectedChanges(new ChangedPaths(new HardenedGit())), new Mirrors($this->mirrors), $protectedPaths);
    }
}
