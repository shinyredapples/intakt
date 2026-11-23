<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Plugin\GitHubForge\Domain\CiDefinition;
use Intakt\Plugin\GitHubForge\Domain\CiFinding;
use Intakt\Plugin\GitHubForge\Domain\CiScan;
use Intakt\Plugin\GitHubForge\Domain\CiScanResult;
use Intakt\Plugin\GitHubForge\Domain\CiWorkflows;
use Intakt\Plugin\GitHubForge\Domain\CommitNotRead;
use Intakt\Plugin\GitHubForge\Domain\ContainedTrigger;
use Intakt\Plugin\GitHubForge\Domain\HostedRunnerLabel;
use Intakt\Plugin\GitHubForge\Domain\SecretReferences;
use Intakt\Plugin\GitHubForge\Domain\TokenPermissions;
use Intakt\Plugin\GitHubForge\Domain\WorkflowBlockValue;
use Intakt\Plugin\GitHubForge\Domain\WorkflowEntry;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFile;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFileScan;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFinding;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFormNotRead;
use Intakt\Plugin\GitHubForge\Domain\WorkflowInlineValue;
use Intakt\Plugin\GitHubForge\Domain\WorkflowLine;
use Intakt\Plugin\GitHubForge\Domain\WorkflowMapping;
use Intakt\Plugin\GitHubForge\Domain\WorkflowReader;
use Intakt\Plugin\GitHubForge\Domain\WorkflowRisk;
use Intakt\Plugin\GitHubForge\Domain\WorkflowScalar;
use Intakt\Plugin\GitHubForge\Domain\WorkflowSequence;
use Intakt\Plugin\GitHubForge\Domain\WorkflowSide;
use Intakt\Plugin\GitHubForge\Infrastructure\MirrorWorkflows;
use Intakt\Plugin\GitHubForge\UseCase\ScanCiChange\ScanCiChangeCommand;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ScanCiChangeCommand::class)]
#[UsesClass(MirrorWorkflows::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(CiScan::class)]
#[UsesClass(CiScanResult::class)]
#[UsesClass(CiWorkflows::class)]
#[UsesClass(CiFinding::class)]
#[UsesClass(CiDefinition::class)]
#[UsesClass(CommitNotRead::class)]
#[UsesClass(WorkflowFile::class)]
#[UsesClass(WorkflowFileScan::class)]
#[UsesClass(WorkflowReader::class)]
#[UsesClass(WorkflowInlineValue::class)]
#[UsesClass(WorkflowBlockValue::class)]
#[UsesClass(TokenPermissions::class)]
#[UsesClass(SecretReferences::class)]
#[UsesClass(WorkflowLine::class)]
#[UsesClass(WorkflowEntry::class)]
#[UsesClass(WorkflowMapping::class)]
#[UsesClass(WorkflowScalar::class)]
#[UsesClass(WorkflowSequence::class)]
#[UsesClass(WorkflowFinding::class)]
#[UsesClass(WorkflowFormNotRead::class)]
#[UsesClass(WorkflowRisk::class)]
#[UsesClass(WorkflowSide::class)]
#[UsesClass(HostedRunnerLabel::class)]
#[UsesClass(ContainedTrigger::class)]
final class ScanCiChangeCommandTest extends TestCase
{
    private TemporaryRepository $repository;

    public function testAScriptChangeUnderContainedWorkflowsIsPushedWithoutTheOwner(): void
    {
        $this->repository->commitFile('bin/check', "#!/bin/sh\n");

        $tester = $this->execute('head');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("ci bin/check\nrisky: no\nCI change: yes\npush waits for the owner: no\n", $tester->getDisplay());
    }

    public function testEachSidesFindingsAreListedAndARiskyCiChangeWaitsForTheOwner(): void
    {
        $this->repository->commitFile('.github/workflows/deploy.yml', $this->fixture('risky.yml'));
        $this->repository->checkout('main');
        $this->repository->commitFile('.github/workflows/lint.yml', $this->fixture('unreadable.yml'));

        $tester = $this->execute('head');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(<<<'TEXT'
            head .github/workflows/deploy.yml line 4: trigger: the trigger pull_request_target may run with the base repository's rights
            head .github/workflows/deploy.yml line 6: permissions: the token may write contents
            head .github/workflows/deploy.yml line 9: unreadable: the job deploy takes its runner from an expression the scan does not read, whose value can change without a commit
            head .github/workflows/deploy.yml line 11: secret: reads a repository secret (secrets.DEPLOY_KEY)
            head .github/workflows/deploy.yml line 16: runner: the job build runs on self-hosted, not a GitHub-hosted runner label
            head .github/workflows/deploy.yml line 16: runner: the job build runs on linux, not a GitHub-hosted runner label
            main .github/workflows/lint.yml line 10: unreadable: a line of a block value indented less than its first line
            merged .github/workflows/deploy.yml line 4: trigger: the trigger pull_request_target may run with the base repository's rights
            merged .github/workflows/deploy.yml line 6: permissions: the token may write contents
            merged .github/workflows/deploy.yml line 9: unreadable: the job deploy takes its runner from an expression the scan does not read, whose value can change without a commit
            merged .github/workflows/deploy.yml line 11: secret: reads a repository secret (secrets.DEPLOY_KEY)
            merged .github/workflows/deploy.yml line 16: runner: the job build runs on self-hosted, not a GitHub-hosted runner label
            merged .github/workflows/deploy.yml line 16: runner: the job build runs on linux, not a GitHub-hosted runner label
            merged .github/workflows/lint.yml line 10: unreadable: a line of a block value indented less than its first line
            ci .github/workflows/deploy.yml
            ci .github/workflows/lint.yml
            risky: yes
            CI change: yes
            push waits for the owner: yes

            TEXT, $tester->getDisplay());
    }

    public function testAHeadThatLinksTheWorkflowsFolderElsewhereWaitsForTheOwner(): void
    {
        $this->repository->commitFile('other/deploy.yml', $this->fixture('risky.yml'));
        $this->repository->removeFile('.github/workflows/checks.yml');
        rmdir($this->repository->path.'/.github/workflows');
        symlink('../other', $this->repository->path.'/.github/workflows');
        $this->repository->commitFile('bin/check', "#!/bin/sh\n");

        $tester = $this->execute('head');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString("head .github/workflows line 0: unreadable: the workflow could not be read: a blob entry with mode 120000, not a folder\n", $tester->getDisplay());
        self::assertStringEndsWith("push waits for the owner: yes\n", $tester->getDisplay());
    }

    public function testAFileNameThatWouldForgeALineIsEscaped(): void
    {
        $this->repository->commitFile("src/a\nci bin/check", "x\n");
        $this->repository->commitFile(".github/workflows/x\e[2J.yml", "on: [push\n");

        $display = $this->execute('head')->getDisplay();

        self::assertStringContainsString('head .github/workflows/x\033[2J.yml line 1: unreadable', $display);
        self::assertStringContainsString('ci src/a\nci bin/check', $display);
    }

    public function testAHeadThatIsNoCommitIsInvalid(): void
    {
        $tester = $this->execute('missing');

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('missing names no commit of the repository', $tester->getDisplay());
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('.github/workflows/checks.yml', $this->fixture('contained.yml'));
        $this->repository->checkoutNewBranch('head');
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/workflows/'.$name);
    }

    private function execute(string $head): CommandTester
    {
        $reader = new WorkflowReader(new WorkflowInlineValue(), new WorkflowBlockValue());
        $scan = new CiScan(new WorkflowFileScan($reader, new TokenPermissions(), new SecretReferences()), new CiDefinition($reader));
        $application = new Application();
        $application->addCommand(new ScanCiChangeCommand(new MirrorWorkflows(new HardenedGit()), $scan));
        $tester = new CommandTester($application->find('intakt:forge:ci-scan'));
        $this->repository->checkout('main');
        $tester->execute(['repository' => $this->repository->path.'/.git', 'head' => $head]);

        return $tester;
    }
}
