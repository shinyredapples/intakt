<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangedFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\Diff;
use Intakt\Plugin\Forge\Contract\FileStatus;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalGitForge::class)]
#[UsesClass(Change::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ChangedFile::class)]
#[UsesClass(ChangedFiles::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(CommitRange::class)]
#[UsesClass(Diff::class)]
#[UsesClass(FileStatus::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(HeadMove::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(NameStatusParser::class)]
final class HeadMoveTest extends TestCase
{
    private TemporaryRepository $repository;

    private string $reviewed;

    public function testAnUnchangedHeadHasNotMoved(): void
    {
        $move = $this->moveSinceReview();

        self::assertSame(HeadMoveKind::Unmoved, $move->kind);
        self::assertSame([], [...$move->paths]);
    }

    public function testANewCommitOnTopMovesTheHeadByCommitsWithTheirPaths(): void
    {
        $this->repository->commitFile('docs/feature.md', "feature\n");

        $move = $this->moveSinceReview();

        self::assertSame(HeadMoveKind::Commits, $move->kind);
        self::assertSame(['docs/feature.md'], [...$move->paths]);
    }

    public function testAMergeOfTheBaseThatGitMadeByItselfIsACleanMergeOfTheBase(): void
    {
        $this->givenTheBaseMovedOn();

        $this->repository->merge('main');

        $move = $this->moveSinceReview();
        self::assertSame(HeadMoveKind::CleanMergeOfBase, $move->kind);
        self::assertSame(['src/Main.php'], [...$move->paths]);
    }

    public function testAMergeOfTheBaseRunsNoMergeDriverTheRepositoryNamesAsDefault(): void
    {
        $this->givenBothSidesEditedTheReadme();
        $this->repository->merge('main');
        $this->repository->configure('merge.default', 'unsafe');
        $this->repository->configure('merge.unsafe.driver', 'touch driver-ran; false');

        $move = $this->moveSinceReview();

        self::assertFileDoesNotExist($this->repository->path.'/driver-ran');
        self::assertSame(HeadMoveKind::CleanMergeOfBase, $move->kind);
    }

    public function testAMergeOfTheBaseRunsNoMergeDriverTheRepositoryDefinesUnderTheBuiltInName(): void
    {
        $this->givenBothSidesEditedTheReadme();
        $this->repository->merge('main');
        $this->repository->configure('merge.text.driver', 'touch driver-ran; false');

        $move = $this->moveSinceReview();

        self::assertFileDoesNotExist($this->repository->path.'/driver-ran');
        self::assertSame(HeadMoveKind::CleanMergeOfBase, $move->kind);
    }

    public function testAMergeOfTheBaseWithAResolvedConflictIsNotClean(): void
    {
        $this->repository->commitFile('README.md', "feature\n");
        $this->reviewed = $this->repository->objectAt('HEAD');
        $this->repository->checkout('main');
        $this->repository->commitFile('README.md', "main\n");
        $this->repository->checkout('feature');

        $this->repository->mergeChanging('main', 'README.md', "both\n");

        self::assertSame(HeadMoveKind::Commits, $this->moveSinceReview()->kind);
    }

    public function testAMergeOfTheBaseWithAnEditHiddenInItIsNotClean(): void
    {
        $this->givenTheBaseMovedOn();

        $this->repository->mergeChanging('main', 'src/Feature.php', "<?php\necho 'hidden';\n");

        self::assertSame(HeadMoveKind::Commits, $this->moveSinceReview()->kind);
    }

    public function testAMergeOfABranchOtherThanTheBaseIsNotAMergeOfTheBase(): void
    {
        $this->repository->checkout('main');
        $this->repository->checkoutNewBranch('other');
        $this->repository->commitFile('src/Other.php', "<?php\n");
        $this->repository->checkout('feature');

        $this->repository->merge('other');

        self::assertSame(HeadMoveKind::Commits, $this->moveSinceReview()->kind);
    }

    public function testACommitAfterACleanMergeOfTheBaseMovesTheHeadByCommits(): void
    {
        $this->givenTheBaseMovedOn();
        $this->repository->merge('main');

        $this->repository->commitFile('src/Feature.php', "<?php\necho 1;\n");

        $move = $this->moveSinceReview();
        self::assertSame(HeadMoveKind::Commits, $move->kind);
        self::assertSame(['src/Feature.php', 'src/Main.php'], [...$move->paths]);
    }

    public function testAHeadThatNoLongerContainsTheReviewedCommitWasRewritten(): void
    {
        $this->repository->checkout('main');
        $this->repository->checkoutNewBranch('rewritten');
        $this->repository->commitFile('src/Feature.php', "<?php\necho 'again';\n");

        $move = $this->forge()->headMoveSince(new CommitId($this->reviewed), $this->forge()->change(new ChangeId('rewritten')));

        self::assertSame(HeadMoveKind::Rewritten, $move->kind);
        self::assertSame([], [...$move->paths]);
    }

    public function testAReviewedCommitThatIsNotInTheRepositoryWasRewritten(): void
    {
        $this->reviewed = str_repeat('ab', 20);

        self::assertSame(HeadMoveKind::Rewritten, $this->moveSinceReview()->kind);
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
        $this->repository->checkoutNewBranch('feature');
        $this->reviewed = $this->repository->commitFile('src/Feature.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    private function givenTheBaseMovedOn(): void
    {
        $this->repository->checkout('main');
        $this->repository->commitFile('src/Main.php', "<?php\n");
        $this->repository->checkout('feature');
    }

    // Because git starts a merge driver only for a file both sides changed.
    private function givenBothSidesEditedTheReadme(): void
    {
        $this->repository->checkout('main');
        $this->repository->commitFile('README.md', "one\ntwo\nthree\n");
        $this->repository->checkout('feature');
        $this->repository->merge('main');
        $this->reviewed = $this->repository->commitFile('README.md', "ONE\ntwo\nthree\n");
        $this->repository->checkout('main');
        $this->repository->commitFile('README.md', "one\ntwo\nTHREE\n");
        $this->repository->checkout('feature');
    }

    private function moveSinceReview(): HeadMove
    {
        $forge = $this->forge();

        return $forge->headMoveSince(new CommitId($this->reviewed), $forge->change(new ChangeId('feature')));
    }

    private function forge(): LocalGitForge
    {
        return new LocalGitForge(new LocalRepository($this->repository->path, 'main'), new NameStatusParser());
    }
}
