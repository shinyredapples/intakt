<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Review\Contract\DocsPages;
use Intakt\Core\Review\Contract\PathGlob;
use Intakt\Core\Review\Infrastructure\DiffPaths;
use Intakt\Core\Review\Infrastructure\DocsPartOfDiff;
use Intakt\Plugin\Forge\Contract\BinaryFiles;
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
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Plugin\Forge\CommitIdCheckThatThrows;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(LocalGitForge::class)]
#[UsesClass(BinaryFiles::class)]
#[UsesClass(Change::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ChangeNotFound::class)]
#[UsesClass(ChangedFile::class)]
#[UsesClass(ChangedFiles::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(CommitRange::class)]
#[UsesClass(Diff::class)]
#[UsesClass(DiffPaths::class)]
#[UsesClass(DocsPages::class)]
#[UsesClass(DocsPartOfDiff::class)]
#[UsesClass(PathGlob::class)]
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
    // The start of a real PNG, because it holds both a NUL byte and bytes that are not UTF-8.
    private const string PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x02\0\0\0";

    private TemporaryRepository $repository;

    /** @var array<array-key, mixed> */
    private array $environment;

    #[RunInSeparateProcess]
    public function testAnUnexpectedInvalidArgumentWhileReadingAHashFromGitPassesThrough(): void
    {
        CommitIdCheckThatThrows::defineFor($this->repository->objectAt('main'));

        $this->expectExceptionObject(new InvalidArgumentException('unexpected'));

        $this->forge()->baseCommit();
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

    public function testItReturnsTheChangeAtTheGivenHeadEvenWhenTheBranchHasMovedPastIt(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $checked = $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->commitFile('src/Later.php', "<?php\n");

        $change = $this->forge()->changeAt(new ChangeId('7'), new CommitId($checked));

        self::assertSame($checked, $change->commits->head->hash);
        self::assertSame(['src/New.php'], array_map(static fn (ChangedFile $file): string => $file->path, [...$change->diff->files]));
    }

    public function testItRefusesTheChangeAtAHeadThatIsNotInTheRepository(): void
    {
        try {
            $this->forge()->changeAt(new ChangeId('7'), new CommitId(str_repeat('a', 40)));
            self::fail('A head missing from the repository was read.');
        } catch (ForgeReadFailed $failure) {
            self::assertStringContainsString('is not in the local repository', $failure->getMessage());
        }
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

    public function testItReturnsTheDiffOfABranchInASha256Repository(): void
    {
        $this->repository->remove();
        $this->repository = new TemporaryRepository('sha256');
        $this->repository->commitFile('README.md', "base\n");
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('README.md', "changed\n");

        $change = $this->forge()->change(new ChangeId('feature'));

        self::assertSame([['README.md', FileStatus::Modified]], $this->pathsAndStatuses($change));
        self::assertStringContainsString('+changed', $change->diff->patch);
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function prefixSettings(): iterable
    {
        yield 'no prefix' => ['diff.noprefix', 'true'];
        yield 'source prefix' => ['diff.srcPrefix', 'old/'];
        yield 'destination prefix' => ['diff.dstPrefix', 'new/'];
    }

    #[DataProvider('prefixSettings')]
    public function testAChangedPageStillReachesTheWritersDiffWhateverPrefixTheRepositoryConfigures(string $setting, string $value): void
    {
        $this->repository->configure($setting, $value);
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('docs/user/cart.md', "The cart keeps a discount.\n");

        $patch = $this->forge()->change(new ChangeId('feature'))->diff->patch;

        self::assertStringContainsString("diff --git a/docs/user/cart.md b/docs/user/cart.md\n", $patch);
        self::assertStringContainsString('+The cart keeps a discount.', new DocsPartOfDiff()->of($patch));
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

    public function testItGivesTheWorkADetachedSnapshotOfTheHeadAndRemovesItAfterwards(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $head = $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->checkout('main');

        $seen = $this->forge()->withSnapshotOf(new CommitId($head), fn (string $snapshot): array => [$snapshot, file_get_contents($snapshot.'/src/New.php'), is_file($snapshot.'/.git'), count($this->repository->worktrees())]);

        self::assertSame(["<?php\n", true, 2], array_slice($seen, 1));
        self::assertNotSame(realpath($this->repository->path), $seen[0]);
        self::assertDirectoryDoesNotExist($seen[0]);
        self::assertCount(1, $this->repository->worktrees());
        self::assertFileDoesNotExist($this->repository->path.'/src/New.php');
    }

    public function testItRemovesTheSnapshotWhenTheWorkFails(): void
    {
        $head = $this->repository->commitFile('src/New.php', "<?php\n");
        $snapshot = '';

        try {
            $outcome = $this->forge()->withSnapshotOf(new CommitId($head), static function (string $directory) use (&$snapshot): string {
                $snapshot = $directory;

                throw new RuntimeException('the work failed');
            });
        } catch (RuntimeException $failure) {
            $outcome = $failure->getMessage();
        }

        self::assertSame('the work failed', $outcome);
        self::assertDirectoryDoesNotExist($snapshot);
        self::assertCount(1, $this->repository->worktrees());
    }

    public function testItTakesTheSnapshotWithoutTheHookAndTheFilterTheRepositoryConfigures(): void
    {
        $marker = $this->repository->path.'/command-ran';
        $script = $this->repository->writeHook('post-checkout', "#!/bin/sh\ntouch ".$marker."\n");
        $this->repository->commitFile('.gitattributes', "*.php filter=evil\n");
        $head = $this->repository->commitFile('src/New.php', "<?php\n");
        $this->repository->configure('filter.evil.smudge', $script);

        $content = $this->forge()->withSnapshotOf(new CommitId($head), static fn (string $snapshot): string|false => file_get_contents($snapshot.'/src/New.php'));

        self::assertFileDoesNotExist($marker);
        self::assertSame("<?php\n", $content);
    }

    public function testTheSnapshotHoldsAFileTheSparseCheckoutOfTheRepositoryLeavesOut(): void
    {
        $this->repository->commitFile('docs/Kept.md', "kept\n");
        $head = $this->repository->commitFile('src/Left.php', "<?php\n");
        $this->repository->configure('core.sparseCheckout', 'true');
        file_put_contents($this->repository->path.'/.git/info/sparse-checkout', "/docs/\n");

        $held = $this->forge()->withSnapshotOf(new CommitId($head), static fn (string $snapshot): bool => is_file($snapshot.'/src/Left.php'));

        self::assertTrue($held);
    }

    public function testTheSnapshotHoldsTheBytesOfTheHeadWhateverTheLineEndingConfig(): void
    {
        $head = $this->repository->commitFile('src/Lines.php', "<?php\nreturn 1;\n");
        $this->repository->configure('core.autocrlf', 'true');

        $content = $this->forge()->withSnapshotOf(new CommitId($head), static fn (string $snapshot): string|false => file_get_contents($snapshot.'/src/Lines.php'));

        self::assertSame("<?php\nreturn 1;\n", $content);
    }

    public function testItFailsToSnapshotAHeadThatIsNotInTheRepository(): void
    {
        try {
            $outcome = $this->forge()->withSnapshotOf(new CommitId(str_repeat('a', 40)), static fn (string $snapshot): string => $snapshot);
        } catch (ForgeReadFailed $failure) {
            $outcome = $failure->getMessage();
        }

        self::assertStringContainsString('git worktree failed', $outcome);
        self::assertCount(1, $this->repository->worktrees());
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

    public function testItNamesAnAddedPngFileAsBinaryWithItsSizeInsteadOfItsBytes(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('.evidence/shot.png', self::PNG);

        $patch = $this->forge()->change(new ChangeId('feature'))->diff->patch;

        self::assertStringContainsString("diff --git a/.evidence/shot.png b/.evidence/shot.png\n", $patch);
        self::assertStringContainsString("Binary file, not shown: absent at the base, 29 bytes at the head\n", $patch);
        self::assertStringNotContainsString('IHDR', $patch);
        self::assertTrue(mb_check_encoding($patch, 'UTF-8'));
    }

    public function testItNamesAModifiedBinaryFileWithItsSizeAtTheBaseAndAtTheHead(): void
    {
        $this->repository->commitFile('logo.png', self::PNG);
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('logo.png', self::PNG."\xff\xfe");

        $patch = $this->forge()->change(new ChangeId('feature'))->diff->patch;

        self::assertStringContainsString("Binary file, not shown: 29 bytes at the base, 31 bytes at the head\n", $patch);
    }

    public function testItNamesADeletedBinaryFileWithItsSizeAtTheBase(): void
    {
        $this->repository->commitFile('logo.png', self::PNG);
        $this->repository->checkoutNewBranch('feature');
        $this->repository->removeFile('logo.png');

        $patch = $this->forge()->change(new ChangeId('feature'))->diff->patch;

        self::assertStringContainsString("deleted file mode 100644\n", $patch);
        self::assertStringContainsString("Binary file, not shown: 29 bytes at the base, absent at the head\n", $patch);
    }

    public function testItNamesABinaryFileWhoseNameIsNotAsciiByItsQuotedName(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile("bild-\u{e4}.png", self::PNG);
        $this->repository->configure('core.quotePath', 'false');

        $patch = $this->forge()->change(new ChangeId('feature'))->diff->patch;

        self::assertStringContainsString('diff --git "a/bild-\303\244.png" "b/bild-\303\244.png"', $patch);
        self::assertStringContainsString('absent at the base, 29 bytes at the head', $patch);
    }

    public function testItKeepsTheModeLinesOfABinaryFileWhoseModeAloneChangedAndNamesItNotBinary(): void
    {
        $this->repository->commitFile('logo.png', self::PNG);
        $this->repository->checkoutNewBranch('feature');
        $this->repository->makeExecutable('logo.png');

        $patch = $this->forge()->change(new ChangeId('feature'))->diff->patch;

        self::assertSame("diff --git a/logo.png b/logo.png\nold mode 100644\nnew mode 100755\n", $patch);
        self::assertStringNotContainsString('Binary file, not shown', $patch);
        self::assertTrue(mb_check_encoding($patch, 'UTF-8'));
    }

    public function testItRefusesAChangedFileWhoseNameIsNotUtf8SoThatItsNameCannotStandForAnotherFile(): void
    {
        $this->repository->commitFile("caf\u{fffd}{E9}.txt", "unchanged decoy\n");
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile("caf\xe9.txt", "changed content\n");

        try {
            $this->forge()->change(new ChangeId('feature'));
            self::fail('A changed path that is not UTF-8 was listed.');
        } catch (ForgeReadFailed $failure) {
            self::assertSame("git listed a changed path that is not valid UTF-8: caf\u{fffd}{E9}.txt.", $failure->getMessage());
        }
    }

    public function testItRefusesAChangeThatDeletesAFileWhoseNameIsNotUtf8(): void
    {
        $this->repository->commitFile("caf\xe9.txt", "menu\n");
        $this->repository->checkoutNewBranch('feature');
        $this->repository->removeFile("caf\xe9.txt");

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->change(new ChangeId('feature'));
    }

    public function testItListsAChangedFileWhoseNameIsUtf8AsItIs(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile("caf\u{e9} \u{fffd}.txt", "menu\n");

        $change = $this->forge()->change(new ChangeId('feature'));

        self::assertSame([["caf\u{e9} \u{fffd}.txt", FileStatus::Added]], $this->pathsAndStatuses($change));
    }

    public function testItKeepsTheLinesOfATextFileChangedBesideABinaryFile(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('a.png', self::PNG);
        $this->repository->commitFile('b.txt', "plain\n");

        $change = $this->forge()->change(new ChangeId('feature'));

        self::assertStringContainsString("+plain\n", $change->diff->patch);
        self::assertSame(1, $change->diff->addedLines());
        self::assertCount(2, $change->diff->files);
    }

    public function testItShowsTheCodeOfAFileWithAByteThatIsNotUtf8WithThatByteEscaped(): void
    {
        $this->repository->commitFile('src/Auth.php', "<?php\nfunction allowed(): bool { return false; }\n");
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('src/Auth.php', "<?php\n// caf\xe9\nfunction allowed(): bool { return true; }\n");

        $patch = $this->forge()->change(new ChangeId('feature'))->diff->patch;

        self::assertStringContainsString("+// caf\u{fffd}{E9}\n+function allowed(): bool { return true; }\n", $patch);
        self::assertStringNotContainsString('Binary', $patch);
        self::assertTrue(mb_check_encoding($patch, 'UTF-8'));
    }

    public function testItNamesABinaryFileAsBinaryWhenItsNulByteLiesOutsideTheLinesShown(): void
    {
        $start = "\x89PNG\0\n".str_repeat("padding\n", 30);
        $this->repository->commitFile('image.png', $start."old payload\n");
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('image.png', $start."new payload\n");

        $patch = $this->forge()->change(new ChangeId('feature'))->diff->patch;

        self::assertStringContainsString('Binary file, not shown: 258 bytes at the base, 258 bytes at the head', $patch);
        self::assertStringNotContainsString('payload', $patch);
    }

    public function testItNamesAFileAsBinaryByItsBytesWhereGitWouldShowItAsText(): void
    {
        $base = $this->repository->commitFile('NOTES.md', "notes\n");
        $this->repository->checkoutNewBranch('feature');
        $head = $this->repository->commitFile('late.txt', str_repeat("text\n", 2000)."\0secret\n");

        self::assertStringNotContainsString('Binary files', $this->repository->diff($base, $head), 'git must see text where the NUL byte comes late, or this test proves nothing');

        $patch = $this->forge()->change(new ChangeId('feature'))->diff->patch;

        self::assertStringContainsString('Binary file, not shown: absent at the base, 10008 bytes at the head', $patch);
        self::assertStringNotContainsString('secret', $patch);
    }

    public function testItShowsAUtf8TextFileThatGitWouldHideAsBinaryAsText(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('notes.txt', "caf\u{e9}\n");
        $this->repository->commitFile('.gitattributes', "*.txt -diff\n");

        $patch = $this->forge()->change(new ChangeId('feature'))->diff->patch;

        self::assertStringContainsString("+caf\u{e9}\n", $patch);
        self::assertStringNotContainsString('Binary', $patch);
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

    public function testItRefusesAHeadMoveWhoseRangeListsAPathThatIsNotUtf8(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('src/New.php', "old\n");
        $earlier = $this->repository->commitFile("caf\xe9.txt", "menu\n");
        $this->repository->removeFile("caf\xe9.txt");
        $moved = $this->forge()->change(new ChangeId('feature'));

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->headMoveSince(new CommitId($earlier), $moved);
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

    public function testItReturnsAPngFileAtTheHeadAsBinaryWithItsSizeInsteadOfItsBytes(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('.evidence/shot.png', self::PNG);
        $change = $this->forge()->change(new ChangeId('feature'));

        $file = $this->forge()->fileAtHead($change, '.evidence/shot.png');

        self::assertSame(29, $file->binaryBytes);
        self::assertSame('Binary file, not shown: 29 bytes', $file->content);
    }

    public function testItReturnsAPngFileAtACommitAsBinaryWithItsSizeInsteadOfItsBytes(): void
    {
        $commit = new CommitId($this->repository->commitFile('logo.png', self::PNG));

        $file = $this->forge()->fileAt($commit, 'logo.png');

        self::assertSame(29, $file->binaryBytes);
        self::assertTrue(mb_check_encoding($file->content, 'UTF-8'));
        self::assertStringNotContainsString('PNG', $file->content);
    }

    public function testItReturnsAFileWhoseOnlyNulByteIsItsLastByteAsBinary(): void
    {
        $commit = new CommitId($this->repository->commitFile('data.txt', str_repeat("text\n", 50_000)."\0"));

        $file = $this->forge()->fileAt($commit, 'data.txt');

        self::assertSame(250_001, $file->binaryBytes);
    }

    public function testItReturnsATextFileAtACommitWithItsBytes(): void
    {
        $commit = new CommitId($this->repository->commitFile('notes.txt', "caf\xe9\n"));

        $file = $this->forge()->fileAt($commit, 'notes.txt');

        self::assertNull($file->binaryBytes);
        self::assertSame("caf\xe9\n", $file->content);
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
