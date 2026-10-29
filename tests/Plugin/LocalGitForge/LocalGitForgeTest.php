<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangedFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\Diff;
use Intakt\Plugin\Forge\Contract\FileContent;
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
#[UsesClass(ChangedFiles::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(CommitRange::class)]
#[UsesClass(Diff::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(FileContent::class)]
#[UsesClass(FileNotFoundAtHead::class)]
#[UsesClass(FileStatus::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(NameStatusParser::class)]
#[UsesClass(RepositoryAttributesRefused::class)]
final class LocalGitForgeTest extends TestCase
{
    private TemporaryRepository $repository;

    /** @var array<array-key, mixed> */
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
        $this->repository->commitFile('src/Gone.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        $_ENV = $this->environment;
        $this->repository->remove();
    }

    public function testItReturnsTheHeadAndTheBaseOfABranchAgainstItsBase(): void
    {
        $baseHash = $this->repository->commitFile('NOTES.md', "notes\n");
        $this->repository->checkoutNewBranch('feature');
        $headHash = $this->repository->commitFile('src/New.php', "<?php\n");

        $change = $this->forge()->change(new ChangeId('feature'));

        self::assertSame($headHash, $change->commits->head->hash);
        self::assertSame($baseHash, $change->commits->base->hash);
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
        ], $this->pathsAndStatuses($change));
        self::assertStringContainsString('+echo 1;', $change->diff->patch);
        self::assertStringContainsString('+changed', $change->diff->patch);
        self::assertStringContainsString('-<?php', $change->diff->patch);
    }

    public function testItLeavesOutWhatTheBaseBranchChangedAfterTheBranchWasCut(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->checkout('main');
        $this->repository->commitFile('ONLY_ON_MAIN.md', "main\n");

        $change = $this->forge()->change(new ChangeId('feature'));

        self::assertCount(1, $change->diff->files);
        self::assertStringNotContainsString('ONLY_ON_MAIN', $change->diff->patch);
    }

    public function testItListsAChangedSubmoduleEvenWhenTheRepositoryIgnoresSubmodules(): void
    {
        $this->repository->commitSubmodule('lib', str_repeat('a', 40));
        $this->repository->configure('diff.ignoreSubmodules', 'all');
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitSubmodule('lib', str_repeat('b', 40));

        $change = $this->forge()->change(new ChangeId('feature'));

        self::assertSame([['lib', FileStatus::Modified]], $this->pathsAndStatuses($change));
        self::assertStringContainsString('+Subproject commit '.str_repeat('b', 40), $change->diff->patch);
    }

    public function testItReturnsAFileAsItIsAtTheExactHead(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $headHash = $this->repository->commitFile('src/New.php', "<?php\necho 'head';\n");

        $content = $this->forge()->fileAtHead($this->forge()->change(new ChangeId('feature')), 'src/New.php');

        self::assertSame("<?php\necho 'head';\n", $content->content);
        self::assertSame($headHash, $content->commit->hash);
    }

    public function testItIgnoresAReplacementOfTheHeadCommit(): void
    {
        $this->repository->checkoutNewBranch('decoy');
        $decoy = $this->repository->commitFile('src/New.php', "decoy\n");
        $this->repository->checkout('main');
        $this->repository->checkoutNewBranch('feature');
        $head = $this->repository->commitFile('src/New.php', "head\n");
        $this->repository->replace($head, $decoy);

        $change = $this->forge()->change(new ChangeId('feature'));
        $content = $this->forge()->fileAtHead($change, 'src/New.php');

        self::assertSame("head\n", $content->content);
        self::assertStringContainsString('+head', $change->diff->patch);
        self::assertStringNotContainsString('decoy', $change->diff->patch);
    }

    public function testItIgnoresAReplacementOfAFileAtTheHead(): void
    {
        $this->repository->checkoutNewBranch('decoy');
        $this->repository->commitFile('src/New.php', "decoy\n");
        $this->repository->checkout('main');
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('src/New.php', "head\n");
        $this->repository->replace($this->repository->objectAt('feature:src/New.php'), $this->repository->objectAt('decoy:src/New.php'));

        $change = $this->forge()->change(new ChangeId('feature'));
        $content = $this->forge()->fileAtHead($change, 'src/New.php');

        self::assertSame("head\n", $content->content);
        self::assertStringNotContainsString('decoy', $change->diff->patch);
    }

    public function testItRunsNoCommandThatTheRepositoryConfigures(): void
    {
        $marker = $this->repository->path.'/command-ran';
        $script = $this->repository->writeHook('evil', "#!/bin/sh\ntouch ".$marker."\ncat \"$1\" 2>/dev/null\n");
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('.gitattributes', "*.txt diff=evil\n");
        $this->repository->commitFile('note.txt', "head\n");
        $this->repository->configure('core.fsmonitor', $script);
        $this->repository->configure('diff.external', $script);
        $this->repository->configure('diff.evil.textconv', $script);

        $change = $this->forge()->change(new ChangeId('feature'));
        $content = $this->forge()->fileAtHead($change, 'note.txt');

        self::assertFileDoesNotExist($marker);
        self::assertStringContainsString('+head', $change->diff->patch);
        self::assertSame("head\n", $content->content);
    }

    public function testItRefusesARepositoryWhoseInfoAttributesExistBecauseTheyCouldHideAFileAsBinary(): void
    {
        $base = $this->repository->commitFile('NOTES.md', "notes\n");
        $this->repository->checkoutNewBranch('feature');
        $head = $this->repository->commitFile('note.txt', "secret\n");
        file_put_contents($this->repository->path.'/.git/info/attributes', "*.txt -diff\n");

        self::assertStringContainsString('Binary files', $this->repository->diff($base, $head), 'the attribute must hide the file without hardening, or this test proves nothing');

        try {
            $this->forge()->change(new ChangeId('feature'));
            self::fail('A repository with info/attributes must be refused.');
        } catch (ForgeReadFailed $failure) {
            self::assertStringContainsString('/.git/info/attributes exists', $failure->getMessage());
        }
    }

    public function testItShowsABinaryFileAsTextSoThatNothingHidesAChangeAsBinaryFilesDiffer(): void
    {
        $base = $this->repository->commitFile('NOTES.md', "notes\n");
        $this->repository->checkoutNewBranch('feature');
        $head = $this->repository->commitFile('blob.bin', "\0secret\n");

        self::assertStringContainsString('Binary files', $this->repository->diff($base, $head), 'git must treat the file as binary without --text, or this test proves nothing');

        $change = $this->forge()->change(new ChangeId('feature'));

        self::assertStringContainsString("+\0secret", $change->diff->patch);
        self::assertStringNotContainsString('Binary files', $change->diff->patch);
    }

    public function testItReturnsAFileWhoseNameHasSpacesTabsAndColons(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile("src/a b\tc:d.txt", "odd\n");

        $content = $this->forge()->fileAtHead($this->forge()->change(new ChangeId('feature')), "src/a b\tc:d.txt");

        self::assertSame("odd\n", $content->content);
    }

    public function testItReturnsAFileWhoseNameStartsWithAColon(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile(':odd.txt', "colon\n");
        $this->repository->commitFile('odd.txt', "plain\n");

        $content = $this->forge()->fileAtHead($this->forge()->change(new ChangeId('feature')), ':odd.txt');

        self::assertSame("colon\n", $content->content);
    }

    public function testItReturnsAFileWhoseNameLooksLikePathspecMagic(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile(':(exclude)x', "magic\n");

        $content = $this->forge()->fileAtHead($this->forge()->change(new ChangeId('feature')), ':(exclude)x');

        self::assertSame("magic\n", $content->content);
    }

    public function testItFailsToReadAPathWithANulByte(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $change = $this->forge()->change(new ChangeId('feature'));

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->fileAtHead($change, "README.md\0x");
    }

    public function testItFailsToReadAChangeIdWithANulByte(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->forge()->change(new ChangeId("feature\0x"));
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

    public function testItDoesNotTreatATraversingNameAsAChange(): void
    {
        $this->repository->commitFile('src/Tagged.php', "<?php\n");
        $this->repository->tag('v1');

        $this->expectException(ChangeNotFound::class);

        $this->forge()->change(new ChangeId('../tags/v1'));
    }

    public function testItDoesNotTreatARevisionExpressionAsAChange(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('src/New.php', "<?php\n");

        $this->expectException(ChangeNotFound::class);

        $this->forge()->change(new ChangeId('feature~1'));
    }

    public function testItReportsADirectoryAsNotAFileAtTheHead(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $change = $this->forge()->change(new ChangeId('feature'));

        $this->expectException(FileNotFoundAtHead::class);

        $this->forge()->fileAtHead($change, 'src');
    }

    public function testItReportsAFileThatIsNotAtTheHead(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->removeFile('src/Gone.php');
        $change = $this->forge()->change(new ChangeId('feature'));

        $this->expectException(FileNotFoundAtHead::class);

        $this->forge()->fileAtHead($change, 'src/Gone.php');
    }

    public function testItFailsToReadAFileWhenTheHeadIsNotInTheRepository(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $read = $this->forge()->change(new ChangeId('feature'));
        $unknownHead = new Change($read->id, new CommitRange($read->commits->base, new CommitId(str_repeat('c', 40))), $read->diff);

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->fileAtHead($unknownHead, 'README.md');
    }

    public function testItFailsWhenTheBaseBranchDoesNotExist(): void
    {
        $this->repository->checkoutNewBranch('feature');

        $this->expectException(ForgeReadFailed::class);

        $this->forge('nowhere')->change(new ChangeId('feature'));
    }

    public function testItFailsWhenTheRepositoryDirectoryDoesNotExist(): void
    {
        $forge = new LocalGitForge(new LocalRepository($this->repository->path.'/missing', 'main'), new NameStatusParser());

        $this->expectException(ForgeReadFailed::class);

        $forge->change(new ChangeId('feature'));
    }

    public function testItFailsWhenTheDirectoryIsNotARepository(): void
    {
        $plain = $this->repository->path.'/plain';
        mkdir($plain);
        file_put_contents($plain.'/.git', "gitdir: nowhere\n");
        $forge = new LocalGitForge(new LocalRepository($plain, 'main'), new NameStatusParser());

        $this->expectException(ForgeReadFailed::class);

        $forge->change(new ChangeId('feature'));
    }

    public function testItIgnoresTheUserConfigOfTheHome(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $home = $this->repository->path.'/home';
        mkdir($home);
        file_put_contents($home.'/.gitconfig', "[broken\n");
        $_ENV['HOME'] = $home;

        $change = $this->forge()->change(new ChangeId('feature'));

        self::assertSame('feature', $change->id->value);
    }

    public function testItIgnoresAGitDirectoryItInherits(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $_ENV['GIT_DIR'] = $this->repository->path.'/missing';

        $change = $this->forge()->change(new ChangeId('feature'));

        self::assertSame('feature', $change->id->value);
    }

    private function forge(string $baseBranch = 'main'): LocalGitForge
    {
        return new LocalGitForge(new LocalRepository($this->repository->path, $baseBranch), new NameStatusParser());
    }

    /**
     * @return list<array{string, FileStatus}>
     */
    private function pathsAndStatuses(Change $change): array
    {
        return array_map(static fn (ChangedFile $file): array => [$file->path, $file->status], [...$change->diff->files]);
    }
}
