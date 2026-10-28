<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangedFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\Diff;
use Intakt\Plugin\Forge\Contract\FileStatus;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\UseCase\SummarizeChange\SummarizeChangeCommand;
use Intakt\Plugin\LocalGitForge\UseCase\SummarizeChange\SummarizeChangeInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(SummarizeChangeCommand::class)]
#[UsesClass(SummarizeChangeInput::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(NameStatusParser::class)]
#[UsesClass(LocalRepository::class)]
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
        self::assertStringContainsString('1 files changed, +1 -0 lines', $tester->getDisplay());
    }

    public function testAMissingBranchFailsWithAMessage(): void
    {
        $tester = $this->whenTheCommandRuns(['branch' => 'nope', '--repository' => $this->repository->path]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('nope', $tester->getDisplay());
    }

    public function testAnUnreadableRepositoryFailsWithAMessage(): void
    {
        $tester = $this->whenTheCommandRuns(['branch' => 'feature', '--repository' => $this->repository->path.'/missing']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    /**
     * @param array<string, string> $input
     */
    private function whenTheCommandRuns(array $input): CommandTester
    {
        $application = new Application();
        $application->addCommand(new SummarizeChangeCommand(new NameStatusParser()));
        $tester = new CommandTester($application->find('intakt:change:summarize'));
        $tester->execute($input);

        return $tester;
    }
}
