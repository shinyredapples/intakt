<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use DateTimeImmutable;
use Intakt\Core\Build\UseCase\BuildTask\BuildTask;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskCommand;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskInput;
use Intakt\Core\Build\UseCase\BuildTask\TaskOutcome;
use Intakt\Core\Build\UseCase\BuildTask\TaskStep;
use Intakt\Core\Build\UseCase\BuildTask\TaskStepNotRecorded;
use Intakt\Core\Git\Contract\BuildCloneNotMade;
use Intakt\Core\Git\Contract\BuildClones;
use Intakt\Core\Git\Contract\ProjectMirror;
use Intakt\Core\Watcher\Contract\ProjectRepositories;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BuildTaskCommand::class)]
#[UsesClass(BuildTaskInput::class)]
#[UsesClass(BuildTask::class)]
#[UsesClass(TaskOutcome::class)]
#[UsesClass(TaskStep::class)]
#[UsesClass(TaskStepNotRecorded::class)]
#[UsesClass(ProjectRepositories::class)]
#[UsesClass(BuildCloneNotMade::class)]
#[UsesClass(IssueListing::class)]
#[UsesClass(ListedIssue::class)]
final class BuildTaskCommandTest extends TestCase
{
    public function testTheCommandPrintsEveryStepOfAPassingRunAndExitsWithZero(): void
    {
        $tester = $this->whenTheCommandRuns(new InMemoryLedger(), $this->createStub(BuildClones::class));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("task done\nmirror done\nclone done\nclone /builds/alpha-7\n", $tester->getDisplay());
    }

    public function testTheCommandPrintsTheStepWhereARunStoppedAndExitsWithOne(): void
    {
        $clones = $this->createStub(BuildClones::class);
        $clones->method('make')->willThrowException(BuildCloneNotMade::at('dir', 'the folder already exists'));

        $tester = $this->whenTheCommandRuns(new InMemoryLedger(), $clones);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString("task done\nmirror done\n", $tester->getDisplay());
        self::assertStringContainsString('The clone step failed', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testTheCommandExitsWithOneWhenTheLedgerDoesNotRecordAStep(): void
    {
        $tester = $this->whenTheCommandRuns(new InMemoryLedger(0), $this->createStub(BuildClones::class));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('did not record', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testTheCommandRefusesAnIssueNumberBelowOne(): void
    {
        $tester = $this->whenTheCommandRuns(new InMemoryLedger(), $this->createStub(BuildClones::class), '0');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('at least 1', $tester->getDisplay());
    }

    private function whenTheCommandRuns(InMemoryLedger $ledger, BuildClones $clones, string $issue = '7'): CommandTester
    {
        $issues = $this->createStub(IssueReads::class);
        $issues->method('listOpen')->willReturn(IssueListing::listed([new ListedIssue(7, 'Title', 'Body', [], 'someone', new DateTimeImmutable('2026-01-01T00:00:00Z'), null, false)], null));
        $task = new BuildTask($issues, new ProjectRepositories(['alpha' => ['repository' => 'remote']]), $this->createStub(ProjectMirror::class), $clones, $ledger, '/builds');

        $application = new Application();
        $application->addCommand(new BuildTaskCommand($task));
        $tester = new CommandTester($application->find('intakt:build:task'));
        $tester->execute(['project' => 'alpha', 'issue' => $issue]);

        return $tester;
    }
}
