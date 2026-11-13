<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\DecideDependencyChange;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Domain\ChangeNotRead;
use Intakt\Core\Git\UseCase\DecideDependencyChange\DecideDependencyChange;
use Intakt\Core\Git\UseCase\DecideDependencyChange\RevisionFiles;
use Intakt\Core\Git\UseCase\ListProtectedChanges\ChangedPaths;
use Intakt\Plugin\Language\Contract\DependencyDecision;
use Intakt\Plugin\Language\Contract\DependencyFiles;
use Intakt\Plugin\Language\Contract\DependencyFinding;
use Intakt\Plugin\PhpLanguage\Domain\ComposerPolicy;
use Intakt\Plugin\PhpLanguage\Domain\ComposerState;
use Intakt\Plugin\PhpLanguage\Domain\GitHubCommit;
use Intakt\Plugin\PhpLanguage\Domain\LockedPackage;
use Intakt\Plugin\PhpLanguage\Domain\LockedPackageChanges;
use Intakt\Plugin\PhpLanguage\Domain\RequirementChanges;
use Intakt\Plugin\PhpLanguage\Domain\SettingChanges;
use Intakt\Plugin\PhpLanguage\Domain\TrustedPackages;
use Intakt\Plugin\PhpLanguage\Infrastructure\PhpLanguage;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Plugin\PhpLanguage\ComposerFiles;
use Intakt\Tests\Plugin\PhpLanguage\LockedCommits;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DecideDependencyChange::class)]
#[CoversClass(RevisionFiles::class)]
#[UsesClass(ChangedPaths::class)]
#[UsesClass(ChangeNotRead::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(PhpLanguage::class)]
#[UsesClass(ComposerPolicy::class)]
#[UsesClass(ComposerState::class)]
#[UsesClass(GitHubCommit::class)]
#[UsesClass(LockedPackage::class)]
#[UsesClass(LockedPackageChanges::class)]
#[UsesClass(RequirementChanges::class)]
#[UsesClass(SettingChanges::class)]
#[UsesClass(TrustedPackages::class)]
#[UsesClass(DependencyDecision::class)]
#[UsesClass(DependencyFiles::class)]
#[UsesClass(DependencyFinding::class)]
final class DecideDependencyChangeTest extends TestCase
{
    private TemporaryRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->commit(ComposerFiles::sample());
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    public function testABranchBehindMainIsAskedOnlyAboutThePackageItAdds(): void
    {
        $this->repository->checkoutNewBranch('feat/tool');
        $this->repository->checkout('main');
        $this->commit(ComposerFiles::sample()->requiring('symfony/uid', '^8.0')->locking(ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0)));
        $this->repository->checkout('feat/tool');
        $head = $this->commit(ComposerFiles::sample()->requiring('acme/tool', '^1.0')->locking(ComposerFiles::entry('acme/tool', '1.0.0', LockedCommits::OTHER)));

        $decision = $this->decide($head);

        self::assertEquals([new DependencyFinding('acme/tool', 'is not from a trusted vendor')], $decision->findings);
        self::assertSame(['acme/tool'], $decision->newPackages);
    }

    public function testABranchBehindMainThatAddsATrustedPackageIsRoutine(): void
    {
        $this->repository->checkoutNewBranch('feat/uid');
        $this->repository->checkout('main');
        $this->commit(ComposerFiles::sample()->requiring('acme/tool', '^1.0')->locking(ComposerFiles::entry('acme/tool', '1.0.0', LockedCommits::OTHER)));
        $this->repository->checkout('feat/uid');
        $head = $this->commit(ComposerFiles::sample()->requiring('symfony/uid', '^8.0')->locking(ComposerFiles::entry('symfony/uid', 'v8.0.0', LockedCommits::UID_8_0_0)));

        $decision = $this->decide($head);

        self::assertFalse($decision->needsOwner());
        self::assertSame(['symfony/uid'], $decision->newPackages);
    }

    public function testAHeadWithoutHistoryInCommonWithMainHasEveryPackageNew(): void
    {
        $this->repository->checkoutOrphan('feat/orphan');
        $head = $this->commit(ComposerFiles::sample());

        self::assertSame(['php', 'symfony/console'], $this->decide($head)->newPackages);
    }

    public function testALockFileThatIsASymbolicLinkNeedsTheOwner(): void
    {
        $this->repository->checkoutNewBranch('feat/link');
        unlink($this->repository->path.'/composer.lock');
        symlink('/etc/hostname', $this->repository->path.'/composer.lock');
        $head = $this->repository->commitFile('README.md', 'linked');

        $decision = $this->decide($head);

        self::assertTrue($decision->needsOwner());
        self::assertSame('the change', $decision->findings[0]->subject);
        self::assertStringContainsString('composer.lock at '.$head.' is not a regular file', $decision->findings[0]->reason);
    }

    public function testAHeadThatIsNoCommitOfTheRepositoryNeedsTheOwner(): void
    {
        self::assertTrue($this->decide(str_repeat('a', 40))->needsOwner());
    }

    public function testAHeadThatIsNoFullCommitNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $_ = $this->decide('main');
    }

    private function commit(ComposerFiles $files): string
    {
        $head = '';
        foreach ($files->files()->contents as $path => $contents) {
            $head = $this->repository->commitFile($path, $contents);
        }

        return $head;
    }

    private function decide(string $head): DependencyDecision
    {
        $this->repository->checkout('main');
        $decide = new DecideDependencyChange(new ChangedPaths(new HardenedGit()), new RevisionFiles(new HardenedGit()), new PhpLanguage(LockedCommits::upstream(), ['symfony'], ['library']));

        return $decide->of($this->repository->path.'/.git', $head);
    }
}
