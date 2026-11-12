<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\BranchTrigger;
use Intakt\Plugin\GitHubForge\Domain\CiDefinition;
use Intakt\Plugin\GitHubForge\Domain\HostedRunnerLabel;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFile;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFileScan;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFindings;
use Intakt\Plugin\GitHubForge\Domain\WorkflowJobs;
use Intakt\Plugin\GitHubForge\Domain\WorkflowLine;
use Intakt\Plugin\GitHubForge\Domain\WorkflowLines;
use Intakt\Plugin\GitHubForge\Domain\WorkflowNode;
use Intakt\Plugin\GitHubForge\Domain\WorkflowValue;
use Intakt\Plugin\GitHubForge\Domain\WorkflowRisk;
use Intakt\Plugin\GitHubForge\Domain\WorkflowRiskFinding;
use Intakt\Plugin\GitHubForge\Domain\WorkflowRiskKind;
use Intakt\Plugin\GitHubForge\Domain\WorkflowRiskScan;
use Intakt\Plugin\GitHubForge\Infrastructure\WorkflowFiles;
use Intakt\Plugin\GitHubForge\UseCase\ScanWorkflows\ScanWorkflowsCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ScanWorkflowsCommand::class)]
#[CoversClass(WorkflowFiles::class)]
#[UsesClass(WorkflowRiskScan::class)]
#[UsesClass(WorkflowFileScan::class)]
#[UsesClass(WorkflowLines::class)]
#[UsesClass(WorkflowValue::class)]
#[UsesClass(WorkflowJobs::class)]
#[UsesClass(WorkflowLine::class)]
#[UsesClass(WorkflowNode::class)]
#[UsesClass(WorkflowFindings::class)]
#[UsesClass(WorkflowRisk::class)]
#[UsesClass(WorkflowFile::class)]
#[UsesClass(WorkflowRiskFinding::class)]
#[UsesClass(WorkflowRiskKind::class)]
#[UsesClass(HostedRunnerLabel::class)]
#[UsesClass(BranchTrigger::class)]
#[UsesClass(CiDefinition::class)]
final class ScanWorkflowsCommandTest extends TestCase
{
    private const string HOSTED = "on: pull_request\npermissions:\n  contents: read\njobs:\n  t:\n    runs-on: ubuntu-24.04\n    steps:\n      - run: scripts/test.sh\n";

    private string $checkout;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->checkout = sys_get_temp_dir().'/intakt-workflows-'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir($this->checkout.'/.github/workflows');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->checkout);
    }

    public function testACheckoutWhoseWorkflowsStayOnHostedRunnersIsNotRisky(): void
    {
        $this->filesystem->dumpFile($this->checkout.'/.github/workflows/ci.yml', self::HOSTED);
        $this->filesystem->dumpFile($this->checkout.'/.github/workflows/README.md', 'runs-on: self-hosted');

        $tester = $this->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("Not risky: 1 workflows, every job on a GitHub-hosted runner with a read-only token and no secrets\n", $tester->getDisplay());
    }

    public function testEachFindingIsShownWithItsFileAndLineAndAnEntryThatCannotBeReadIsOne(): void
    {
        $this->filesystem->dumpFile($this->checkout.'/.github/workflows/ci.yml', self::HOSTED);
        $this->filesystem->dumpFile($this->checkout.'/.github/workflows/deploy.yaml', str_replace('ubuntu-24.04', 'self-hosted', self::HOSTED));
        $this->filesystem->mkdir($this->checkout.'/.github/workflows/odd.yml');

        $tester = $this->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(
            ".github/workflows/deploy.yaml:6 not-hosted-runner: the runner label self-hosted is not a GitHub-hosted one\n"
            .".github/workflows/odd.yml:0 unreadable: the file cannot be read as UTF-8 text\n"
            ."Risky: 2 findings in 3 workflows\n",
            $tester->getDisplay(),
        );
    }

    public function testAChangedScriptAWorkflowRunsIsShownAsACiChange(): void
    {
        $this->filesystem->dumpFile($this->checkout.'/.github/workflows/ci.yml', self::HOSTED);

        $tester = $this->execute(['--changed' => ['src/App.php', 'scripts/test.sh']]);

        self::assertStringEndsWith("CI change: scripts/test.sh\n", $tester->getDisplay());
    }

    public function testChangedPathsNoWorkflowRunsAreNoCiChange(): void
    {
        $this->filesystem->dumpFile($this->checkout.'/.github/workflows/ci.yml', self::HOSTED);

        $tester = $this->execute(['--changed' => ['src/App.php']]);

        self::assertStringEndsWith("No CI change among 1 changed paths\n", $tester->getDisplay());
    }

    public function testACheckoutWithoutWorkflowsIsNotRisky(): void
    {
        $this->filesystem->remove($this->checkout.'/.github');

        self::assertStringStartsWith('Not risky: 0 workflows', $this->execute([])->getDisplay());
    }

    public function testAMissingCheckoutFails(): void
    {
        $this->filesystem->remove($this->checkout);

        $tester = $this->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('is not a directory', $tester->getDisplay());
    }

    /** @param array<string, list<string>> $options */
    private function execute(array $options): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ScanWorkflowsCommand(new WorkflowFiles($this->filesystem), new WorkflowRiskScan(), new CiDefinition()));
        $tester = new CommandTester($application->find('intakt:forge:workflow-risk'));
        $tester->execute(['checkout' => $this->checkout, ...$options]);

        return $tester;
    }
}
