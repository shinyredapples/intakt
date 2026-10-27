<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\FileNotFoundAtHead;
use Intakt\Plugin\Forge\Contract\FileStatus;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalGitForge::class)]
#[UsesClass(Change::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ChangeNotFound::class)]
#[UsesClass(ChangedFile::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(FileNotFoundAtHead::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(NameStatusParser::class)]
final class LocalGitForgeTest extends TestCase
{
    private TemporaryRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
        $this->repository->commitFile('src/Gone.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    public function testItReturnsTheHeadAndTheBaseOfABranchAgainstItsBase(): void
    {
        $baseHash = $this->repository->commitFile('NOTES.md', "notes\n");
        $this->repository->checkoutNewBranch('feature');
        $headHash = $this->repository->commitFile('src/New.php', "<?php\n");

        $change = $this->forge()->change(new ChangeId('feature'));

        self::assertSame($headHash, $change->head->hash);
        self::assertSame($baseHash, $change->base->hash);
    }

    public function testItReturnsTheDiffAndTheChangedFilesOfTheBranch(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('src/New.php', "<?php\necho 1;\n");
        $this->repository->commitFile('README.md', "changed\n");
        $this->repository->removeFile('src/Gone.php');

        $change = $this->forge()->change(new ChangeId('feature'));

        self::assertSame([
            ['README.md', FileStatus::Modified],
            ['src/Gone.php', FileStatus::Deleted],
            ['src/New.php', FileStatus::Added],
        ], array_map(static fn (ChangedFile $file): array => [$file->path, $file->status], $change->files));
        self::assertStringContainsString('+echo 1;', $change->diff);
        self::assertStringContainsString('+changed', $change->diff);
        self::assertStringContainsString('-<?php', $change->diff);
    }

    public function testItLeavesOutWhatTheBaseBranchChangedAfterTheBranchWasCut(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->checkout('main');
        $this->repository->commitFile('ONLY_ON_MAIN.md', "main\n");

        $change = $this->forge()->change(new ChangeId('feature'));

        self::assertCount(1, $change->files);
        self::assertStringNotContainsString('ONLY_ON_MAIN', $change->diff);
    }

    public function testItReturnsAFileAsItIsAtTheExactHead(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $headHash = $this->repository->commitFile('src/New.php', "<?php\necho 'head';\n");

        $content = $this->forge()->fileAtHead($this->forge()->change(new ChangeId('feature')), 'src/New.php');

        self::assertSame("<?php\necho 'head';\n", $content->content);
        self::assertSame($headHash, $content->commit->hash);
    }

    public function testItKeepsReadingTheOldHeadAfterTheBranchMovedOn(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('src/New.php', "old\n");
        $change = $this->forge()->change(new ChangeId('feature'));
        $this->repository->commitFile('src/New.php', "new\n");

        $content = $this->forge()->fileAtHead($change, 'src/New.php');

        self::assertSame("old\n", $content->content);
    }

    public function testItDetectsThatTheHeadMoved(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('src/New.php', "old\n");
        $change = $this->forge()->change(new ChangeId('feature'));
        $this->repository->commitFile('src/New.php', "new\n");

        self::assertTrue($this->forge()->hasHeadMoved($change));
    }

    public function testItSeesNoMoveWhileTheHeadStaysTheSame(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('src/New.php', "old\n");
        $change = $this->forge()->change(new ChangeId('feature'));

        self::assertFalse($this->forge()->hasHeadMoved($change));
    }

    public function testItRefusesAChangeWhoseBranchDoesNotExist(): void
    {
        $this->expectException(ChangeNotFound::class);

        $this->forge()->change(new ChangeId('missing'));
    }

    public function testItDoesNotTreatATagAsAChange(): void
    {
        $this->repository->commitFile('src/Tagged.php', "<?php\n");
        $this->repository->tag('v1');

        $this->expectException(ChangeNotFound::class);

        $this->forge()->change(new ChangeId('v1'));
    }

    public function testItFailsWhenTheBaseBranchDoesNotExist(): void
    {
        $this->repository->checkoutNewBranch('feature');

        $this->expectException(ForgeReadFailed::class);

        $this->forge('nowhere')->change(new ChangeId('feature'));
    }

    public function testItReportsAFileThatIsNotAtTheHead(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->removeFile('src/Gone.php');
        $change = $this->forge()->change(new ChangeId('feature'));

        $this->expectException(FileNotFoundAtHead::class);

        $this->forge()->fileAtHead($change, 'src/Gone.php');
    }

    private function forge(string $baseBranch = 'main'): LocalGitForge
    {
        return new LocalGitForge(new LocalRepository($this->repository->path, $baseBranch), new NameStatusParser());
    }
}
