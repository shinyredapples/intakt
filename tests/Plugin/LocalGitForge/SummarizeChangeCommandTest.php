<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangedFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeIdEmpty;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\Diff;
use Intakt\Plugin\Forge\Contract\FileStatus;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepositoryIncomplete;
use Intakt\Plugin\LocalGitForge\Contract\RepositoryNotFound;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\Infrastructure\VisiblePath;
use Intakt\Plugin\LocalGitForge\UseCase\SummarizeChange\SummarizeChangeCommand;
use Intakt\Plugin\LocalGitForge\UseCase\SummarizeChange\SummarizeChangeInput;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(SummarizeChangeCommand::class)]
#[UsesClass(SummarizeChangeInput::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(NameStatusParser::class)]
#[UsesClass(VisiblePath::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(LocalRepositoryIncomplete::class)]
#[UsesClass(RepositoryNotFound::class)]
#[UsesClass(ChangeIdEmpty::class)]
#[UsesClass(Change::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ChangeNotFound::class)]
#[UsesClass(ChangedFile::class)]
#[UsesClass(ChangedFiles::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(CommitRange::class)]
#[UsesClass(Diff::class)]
#[UsesClass(FileStatus::class)]
#[UsesClass(ForgeReadFailed::class)]
final class SummarizeChangeCommandTest extends TestCase
{
    private TemporaryRepository $repository;

    public function testABranchIsSummarizedWithItsFilesAndChangedLines(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('keep.txt', "one\ntwo\nthree\n");
        $this->repository->commitFile('src/new.txt', "a\nb\n");
        $this->repository->removeFile('gone.txt');

        $tester = $this->whenTheCommandRuns(['branch' => 'feature', '--repository' => $this->repository->path]);

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('feature against main', $display);
        self::assertStringContainsString('3 files changed, +3 -1 lines', $display);
        self::assertMatchesRegularExpression('/^modified\s+keep\.txt$/m', $display);
        self::assertMatchesRegularExpression('/^added\s+src\/new\.txt$/m', $display);
        self::assertMatchesRegularExpression('/^deleted\s+gone\.txt$/m', $display);
        self::assertStringContainsString('head '.substr($this->repository->objectAt('feature'), 0, 12), $display);
    }

    public function testTheBaseOptionChoosesTheBranchTheChangeIsMeasuredAgainst(): void
    {
        $this->repository->checkoutNewBranch('develop');
        $this->repository->commitFile('develop.txt', "d\n");
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('feature.txt', "f\n");

        $tester = $this->whenTheCommandRuns(['branch' => 'feature', '--repository' => $this->repository->path, '--base' => 'develop']);

        self::assertStringContainsString('feature against develop', $tester->getDisplay());
        self::assertStringContainsString('1 file changed, +1 -0 lines', $tester->getDisplay());
    }

    public function testAnAddedLineStartingWithTwoPlusSignsIsCounted(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('keep.txt', "one\ntwo\n++counter;\n");

        $tester = $this->whenTheCommandRuns(['branch' => 'feature', '--repository' => $this->repository->path]);

        self::assertStringContainsString('1 file changed, +1 -0 lines', $tester->getDisplay());
    }

    public function testARemovedLineStartingWithTwoMinusSignsIsCounted(): void
    {
        $this->repository->commitFile('query.sql', "-- comment\nselect 1;\n");
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('query.sql', "select 1;\n");

        $tester = $this->whenTheCommandRuns(['branch' => 'feature', '--repository' => $this->repository->path]);

        self::assertStringContainsString('1 file changed, +0 -1 lines', $tester->getDisplay());
    }

    public function testAPathThatLooksLikeAFormatterTagIsPrintedLiterally(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('<info>note.txt', "n\n");

        $tester = $this->whenTheCommandRuns(['branch' => 'feature', '--repository' => $this->repository->path]);

        self::assertMatchesRegularExpression('/^added\s+<info>note\.txt$/m', $tester->getDisplay());
    }

    public function testABranchNameThatLooksLikeAFormatterTagIsPrintedLiterally(): void
    {
        $this->repository->checkoutNewBranch('<error>feature');
        $this->repository->commitFile('feature.txt', "f\n");

        $tester = $this->whenTheCommandRuns(['branch' => '<error>feature', '--repository' => $this->repository->path]);

        self::assertStringContainsString('<error>feature against main', $tester->getDisplay());
    }

    public function testASingleChangedFileIsCountedInTheSingular(): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile('feature.txt', "f\n");

        $tester = $this->whenTheCommandRuns(['branch' => 'feature', '--repository' => $this->repository->path]);

        self::assertStringContainsString('1 file changed, +1 -0 lines', $tester->getDisplay());
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testControlCharactersInAPathArePrintedVisibly(bool $decorated): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile("\e[2Kred\\.txt", "r\n");

        $tester = $this->whenTheCommandRuns(['branch' => 'feature', '--repository' => $this->repository->path], $decorated);

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/^added\s+\\\\x1b\[2Kred\\\\\\\\\.txt$/m', $display);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x09\x0B-\x1F\x7F]/', $display);
    }

    #[TestWith(["c1\u{009B}.txt", 'c1\xc2\x9b.txt'])]
    #[TestWith(["del\x7F.txt", 'del\x7f.txt'])]
    public function testC1CharactersAndDeleteInAPathArePrintedAsEscapes(string $path, string $escaped): void
    {
        $this->repository->checkoutNewBranch('feature');
        $this->repository->commitFile($path, "r\n");

        $tester = $this->whenTheCommandRuns(['branch' => 'feature', '--repository' => $this->repository->path]);

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/^added\s+'.preg_quote($escaped, '/').'$/m', $display);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x09\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', $display);
    }

    public function testAMissingBranchFailsWithAMessage(): void
    {
        $tester = $this->whenTheCommandRuns(['branch' => 'nope', '--repository' => $this->repository->path]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('nope', $tester->getDisplay());
    }

    public function testAnUnreadableRepositoryFailsWithAMessage(): void
    {
        $plain = $this->repository->path.'/plain';
        mkdir($plain);
        file_put_contents($plain.'/.git', "gitdir: nowhere\n");

        $tester = $this->whenTheCommandRuns(['branch' => 'feature', '--repository' => $plain]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('[ERROR] git', $tester->getDisplay());
    }

    public function testAMissingRepositoryFailsNamingThePath(): void
    {
        $tester = $this->whenTheCommandRuns(['branch' => 'feature', '--repository' => 'no-such-repository']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(RepositoryNotFound::at('no-such-repository')->getMessage(), $tester->getDisplay());
    }

    public function testABlankBaseBranchFailsWithAMessage(): void
    {
        $tester = $this->whenTheCommandRuns(['branch' => 'feature', '--repository' => $this->repository->path, '--base' => ' ']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A local repository needs a path and a base branch.', $tester->getDisplay());
    }

    public function testABlankBranchFailsWithAMessage(): void
    {
        $tester = $this->whenTheCommandRuns(['branch' => ' ', '--repository' => $this->repository->path]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A change id is not empty.', $tester->getDisplay());
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('keep.txt', "one\ntwo\n");
        $this->repository->commitFile('gone.txt', "bye\n");
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    /**
     * @param array<string, string> $input
     */
    private function whenTheCommandRuns(array $input, bool $decorated = false): CommandTester
    {
        $application = new Application();
        $application->addCommand(new SummarizeChangeCommand(new NameStatusParser()));
        $tester = new CommandTester($application->find('intakt:change:summarize'));
        $tester->execute($input, ['decorated' => $decorated]);

        return $tester;
    }
}
