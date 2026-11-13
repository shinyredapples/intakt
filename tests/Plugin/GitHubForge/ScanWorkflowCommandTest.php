<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\ContainedTrigger;
use Intakt\Plugin\GitHubForge\Domain\HostedRunnerLabel;
use Intakt\Plugin\GitHubForge\Domain\SecretReferences;
use Intakt\Plugin\GitHubForge\Domain\TokenPermissions;
use Intakt\Plugin\GitHubForge\Domain\WorkflowEntry;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFileScan;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFinding;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFormNotRead;
use Intakt\Plugin\GitHubForge\Domain\WorkflowLine;
use Intakt\Plugin\GitHubForge\Domain\WorkflowLines;
use Intakt\Plugin\GitHubForge\Domain\WorkflowMapping;
use Intakt\Plugin\GitHubForge\Domain\WorkflowRisk;
use Intakt\Plugin\GitHubForge\Domain\WorkflowScalar;
use Intakt\Plugin\GitHubForge\Domain\WorkflowSequence;
use Intakt\Plugin\GitHubForge\UseCase\ScanWorkflow\ScanWorkflowCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ScanWorkflowCommand::class)]
#[UsesClass(WorkflowFileScan::class)]
#[UsesClass(WorkflowLines::class)]
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
#[UsesClass(HostedRunnerLabel::class)]
#[UsesClass(ContainedTrigger::class)]
final class ScanWorkflowCommandTest extends TestCase
{
    public function testAWorkflowInsideTheEnvelopeSucceeds(): void
    {
        $tester = $this->execute(__DIR__.'/Fixtures/workflows/contained.yml');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("no risk: hosted runners, a read-only token, no secrets\n", $tester->getDisplay());
    }

    public function testEachFindingIsListedByLineAndTheCommandFails(): void
    {
        $tester = $this->execute(__DIR__.'/Fixtures/workflows/risky.yml');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(<<<'TEXT'
            line 4: trigger: the trigger pull_request_target may run with the base repository's rights
            line 6: permissions: the token may write contents
            line 9: runner: the job deploy takes its runner from an expression, whose value can change without a commit
            line 11: secret: reads a repository secret (secrets.DEPLOY_KEY)
            line 16: runner: the job build runs on self-hosted, not a GitHub-hosted runner label
            line 16: runner: the job build runs on linux, not a GitHub-hosted runner label
            risky: 6 findings

            TEXT, $tester->getDisplay());
    }

    public function testAFormTheScanCannotReadFails(): void
    {
        $tester = $this->execute(__DIR__.'/Fixtures/workflows/unreadable.yml');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringStartsWith('line 10: unreadable: a line of a block value indented less than its first line', $tester->getDisplay());
    }

    public function testAFileThatCannotBeReadIsInvalid(): void
    {
        $tester = $this->execute(__DIR__.'/Fixtures/workflows');

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Failed to read file', $tester->getDisplay());
    }

    private function execute(string $file): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ScanWorkflowCommand(new WorkflowFileScan(new TokenPermissions(), new SecretReferences()), new Filesystem()));
        $tester = new CommandTester($application->find('intakt:forge:workflow-risk'));
        $tester->execute(['file' => $file]);

        return $tester;
    }
}
