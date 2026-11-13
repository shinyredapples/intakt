<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\CiDefinition;
use Intakt\Plugin\GitHubForge\Domain\CiFinding;
use Intakt\Plugin\GitHubForge\Domain\CiScan;
use Intakt\Plugin\GitHubForge\Domain\CiScanResult;
use Intakt\Plugin\GitHubForge\Domain\CiWorkflows;
use Intakt\Plugin\GitHubForge\Domain\ContainedTrigger;
use Intakt\Plugin\GitHubForge\Domain\HostedRunnerLabel;
use Intakt\Plugin\GitHubForge\Domain\SecretReferences;
use Intakt\Plugin\GitHubForge\Domain\TokenPermissions;
use Intakt\Plugin\GitHubForge\Domain\WorkflowBlockValue;
use Intakt\Plugin\GitHubForge\Domain\WorkflowEntry;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFile;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFileScan;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFinding;
use Intakt\Plugin\GitHubForge\Domain\WorkflowInlineValue;
use Intakt\Plugin\GitHubForge\Domain\WorkflowLine;
use Intakt\Plugin\GitHubForge\Domain\WorkflowMapping;
use Intakt\Plugin\GitHubForge\Domain\WorkflowReader;
use Intakt\Plugin\GitHubForge\Domain\WorkflowRisk;
use Intakt\Plugin\GitHubForge\Domain\WorkflowScalar;
use Intakt\Plugin\GitHubForge\Domain\WorkflowSequence;
use Intakt\Plugin\GitHubForge\Domain\WorkflowSide;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CiScan::class)]
#[CoversClass(CiScanResult::class)]
#[CoversClass(CiWorkflows::class)]
#[UsesClass(CiFinding::class)]
#[UsesClass(WorkflowSide::class)]
#[UsesClass(CiDefinition::class)]
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
#[UsesClass(WorkflowRisk::class)]
#[UsesClass(HostedRunnerLabel::class)]
#[UsesClass(ContainedTrigger::class)]
final class CiScanTest extends TestCase
{
    private const string CI = '.github/workflows/ci.yml';

    public function testAWorkflowThatCouldNotBeReadIsAnUnreadableFinding(): void
    {
        $result = $this->scan(new CiWorkflows([WorkflowFile::unread(self::CI, 'git cat-file failed')], [], [], []));

        self::assertSame([['head', self::CI, 'unreadable', 0, 'the workflow could not be read: git cat-file failed']], $this->summary($result));
    }

    public function testEachFindingNamesTheSideAndTheFileItWasFoundIn(): void
    {
        $result = $this->scan(new CiWorkflows([$this->contained()], [$this->runningOn('self-hosted')], [$this->runningOn('self-hosted'), $this->contained()], []));

        self::assertSame([['main', self::CI, 'runner', 5], ['merged', self::CI, 'runner', 5]], array_map(static fn (array $finding): array => array_slice($finding, 0, 4), $this->summary($result)));
    }

    public function testAScriptChangeHoldsThePushWhileMainsWorkflowsAreRisky(): void
    {
        $result = $this->scan(new CiWorkflows([$this->contained()], [$this->runningOn('self-hosted')], [$this->contained()], ['scripts/test.sh']));

        self::assertSame(['scripts/test.sh'], $result->ciPaths);
        self::assertTrue($result->holdsThePush());
    }

    public function testARunnerVariableHoldsAScriptChangeEvenWhileTheVariableIsUnset(): void
    {
        $result = $this->scan(new CiWorkflows([$this->runningOn('${{ vars.RUNNER }}')], [], [], ['scripts/test.sh']));

        self::assertSame([['head', self::CI, 'unreadable', 5]], array_map(static fn (array $finding): array => array_slice($finding, 0, 4), $this->summary($result)));
        self::assertTrue($result->holdsThePush());
    }

    public function testARiskyWorkflowDoesNotHoldAChangeOutsideTheCiDefinition(): void
    {
        $result = $this->scan(new CiWorkflows([$this->runningOn('self-hosted')], [$this->runningOn('self-hosted')], [$this->runningOn('self-hosted')], ['src/Foo.php']));

        self::assertTrue($result->isRisky());
        self::assertFalse($result->isCiChange());
        self::assertFalse($result->holdsThePush());
    }

    public function testACiChangeInsideTheEnvelopeOnEverySideDoesNotHoldThePush(): void
    {
        $result = $this->scan(new CiWorkflows([$this->contained()], [$this->contained()], [$this->contained()], [self::CI, 'scripts/test.sh']));

        self::assertSame([self::CI, 'scripts/test.sh'], $result->ciPaths);
        self::assertFalse($result->isRisky());
        self::assertFalse($result->holdsThePush());
    }

    public function testAnUnreadMergedWorkflowMakesEveryChangedPathACiChange(): void
    {
        $result = $this->scan(new CiWorkflows([$this->contained()], [$this->contained()], [WorkflowFile::unread('.github/workflows', 'a conflict')], ['src/Foo.php']));

        self::assertSame(['src/Foo.php'], $result->ciPaths);
        self::assertTrue($result->holdsThePush());
    }

    private function scan(CiWorkflows $workflows): CiScanResult
    {
        $reader = new WorkflowReader(new WorkflowInlineValue(), new WorkflowBlockValue());

        return new CiScan(new WorkflowFileScan($reader, new TokenPermissions(), new SecretReferences()), new CiDefinition($reader))->of($workflows);
    }

    private function contained(): WorkflowFile
    {
        return $this->runningOn('ubuntu-24.04');
    }

    private function runningOn(string $runner): WorkflowFile
    {
        return new WorkflowFile(self::CI, "on: push\npermissions: read-all\njobs:\n  check:\n    runs-on: {$runner}\n    steps:\n      - run: ./scripts/test.sh\n");
    }

    /**
     * @return list<array{string, string, string, int, string}>
     */
    private function summary(CiScanResult $result): array
    {
        return array_map(static fn (CiFinding $found): array => [$found->side->value, $found->path, $found->finding->risk->value, $found->finding->line, $found->finding->detail], $result->findings);
    }
}
