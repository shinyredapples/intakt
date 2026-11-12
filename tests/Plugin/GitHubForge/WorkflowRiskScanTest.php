<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\BranchTrigger;
use Intakt\Plugin\GitHubForge\Domain\HostedRunnerLabel;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFile;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFileScan;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFindings;
use Intakt\Plugin\GitHubForge\Domain\WorkflowJobs;
use Intakt\Plugin\GitHubForge\Domain\WorkflowLine;
use Intakt\Plugin\GitHubForge\Domain\WorkflowLines;
use Intakt\Plugin\GitHubForge\Domain\WorkflowNode;
use Intakt\Plugin\GitHubForge\Domain\WorkflowRisk;
use Intakt\Plugin\GitHubForge\Domain\WorkflowRiskFinding;
use Intakt\Plugin\GitHubForge\Domain\WorkflowRiskKind;
use Intakt\Plugin\GitHubForge\Domain\WorkflowRiskScan;
use Intakt\Plugin\GitHubForge\Domain\WorkflowValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkflowRiskScan::class)]
#[CoversClass(WorkflowFileScan::class)]
#[CoversClass(WorkflowLines::class)]
#[CoversClass(WorkflowValue::class)]
#[CoversClass(WorkflowJobs::class)]
#[UsesClass(WorkflowLine::class)]
#[UsesClass(WorkflowNode::class)]
#[UsesClass(WorkflowFindings::class)]
#[CoversClass(WorkflowRisk::class)]
#[UsesClass(WorkflowFile::class)]
#[UsesClass(WorkflowRiskFinding::class)]
#[UsesClass(WorkflowRiskKind::class)]
#[UsesClass(HostedRunnerLabel::class)]
#[UsesClass(BranchTrigger::class)]
final class WorkflowRiskScanTest extends TestCase
{
    private const string READ_ONLY = "on: pull_request\npermissions:\n  contents: read\n";

    public function testAJobOnAHostedRunnerWithAReadOnlyTokenAndNoSecretsIsNotRisky(): void
    {
        $risk = new WorkflowRiskScan()->scan($this->file(self::READ_ONLY."jobs:\n  t:\n    runs-on: ubuntu-24.04\n    steps:\n      - uses: actions/checkout@v4\n        with:\n          persist-credentials: false\n      - run: bin/check\n"));

        self::assertFalse($risk->isRisky());
        self::assertSame([], $risk->findings);
    }

    public function testAQuotedHostedLabelAOneLabelListAndATrailingCommentAreNotRisky(): void
    {
        self::assertSame([], $this->findings(self::READ_ONLY."jobs:\n  a:\n    runs-on: 'macos-latest'\n  b:\n    runs-on: [\"windows-2025\"]\n  c:\n    runs-on: ubuntu-latest # self-hosted\n"));
    }

    public function testAnyOtherRunnerLabelAsks(): void
    {
        self::assertSame(['6 not-hosted-runner'], $this->findings(self::READ_ONLY."jobs:\n  t:\n    runs-on: self-hosted\n"));
    }

    public function testAListThatAddsALabelToAHostedOneAsks(): void
    {
        self::assertSame(['6 not-hosted-runner'], $this->findings(self::READ_ONLY."jobs:\n  t:\n    runs-on: [ubuntu-latest, gpu]\n"));
    }

    public function testAHashInsideAQuotedLabelIsNoComment(): void
    {
        self::assertSame(['6 not-hosted-runner'], $this->findings(self::READ_ONLY."jobs:\n  t:\n    runs-on: 'ubuntu-latest #x'\n"));
    }

    public function testARunsOnFromAVariableIsUnreadableEvenWithAHostedFallback(): void
    {
        $risk = new WorkflowRiskScan()->scan($this->file(self::READ_ONLY."jobs:\n  t:\n    runs-on: \${{ fromJSON(vars.CI_RUNS_ON || '\"ubuntu-24.04\"') }}\n"));

        self::assertTrue($risk->isRisky());
        self::assertSame(['6 unreadable'], $this->summaries($risk));
        self::assertSame('runs-on comes from a variable or an expression, whose value can change without a commit', $risk->findings[0]->detail);
    }

    public function testARunsOnFromAMatrixIsUnreadable(): void
    {
        self::assertSame(['9 unreadable'], $this->findings(self::READ_ONLY."jobs:\n  t:\n    strategy:\n      matrix:\n        os: [ubuntu-latest]\n    runs-on: \${{ matrix.os }}\n"));
    }

    public function testARunsOnSpreadOverTheNextLinesIsUnreadable(): void
    {
        self::assertSame(['6 unreadable'], $this->findings(self::READ_ONLY."jobs:\n  t:\n    runs-on:\n      group: big\n"));
    }

    public function testAJobWithoutRunsOnIsUnreadable(): void
    {
        self::assertSame(['5 unreadable'], $this->findings(self::READ_ONLY."jobs:\n  t:\n    steps:\n      - run: true\n"));
    }

    public function testAJobThatCallsAReusableWorkflowIsUnreadable(): void
    {
        self::assertSame(['6 unreadable'], $this->findings(self::READ_ONLY."jobs:\n  t:\n    uses: acme/ci/.github/workflows/build.yml@v1\n"));
    }

    public function testEveryReadOnlyFormOfPermissionsIsNotRisky(): void
    {
        self::assertSame([], $this->findings("on: push\npermissions: {}\njobs:\n  a:\n    runs-on: ubuntu-latest\n    permissions: read-all\n  b:\n    runs-on: ubuntu-latest\n    permissions:\n      contents: read\n      id-token: none\n"));
    }

    public function testAJobThatRaisesAScopeToWriteAsks(): void
    {
        self::assertSame(['9 writable-token'], $this->findings(self::READ_ONLY."jobs:\n  t:\n    runs-on: ubuntu-latest\n    permissions:\n      contents: read\n      pull-requests: write\n"));
    }

    public function testWriteAllAsks(): void
    {
        self::assertSame(['2 writable-token'], $this->findings("on: push\npermissions: write-all\njobs:\n  t:\n    runs-on: ubuntu-latest\n"));
    }

    public function testAJobWithoutDeclaredPermissionsAsks(): void
    {
        self::assertSame(['3 writable-token'], $this->findings("on: push\njobs:\n  t:\n    runs-on: ubuntu-latest\n  u:\n    runs-on: ubuntu-latest\n    permissions: {}\n"));
    }

    public function testPermissionsWithoutScopesAreUnreadable(): void
    {
        self::assertSame(['2 unreadable'], $this->findings("on: push\npermissions:\njobs:\n  t:\n    runs-on: ubuntu-latest\n"));
    }

    public function testPermissionsFromAnExpressionAreUnreadable(): void
    {
        self::assertSame(['3 unreadable'], $this->findings("on: push\npermissions:\n  contents: \${{ vars.LEVEL }}\njobs:\n  t:\n    runs-on: ubuntu-latest\n"));
    }

    public function testARepositorySecretInAScriptAsks(): void
    {
        self::assertSame(['9 secret'], $this->findings(self::READ_ONLY."jobs:\n  t:\n    runs-on: ubuntu-latest\n    steps:\n      - run: |\n          echo \${{ secrets.DEPLOY_KEY }}\n"));
    }

    public function testEveryReadOfTheWholeSecretsContextAsks(): void
    {
        self::assertSame(['9 secret', '10 secret'], $this->findings(self::READ_ONLY."jobs:\n  t:\n    runs-on: ubuntu-latest\n    steps:\n      - env:\n          ALL: \${{ toJSON(secrets) }}\n        if: secrets.KEY != ''\n        run: true\n"));
    }

    public function testTheReadOnlyTokenIsNoSecret(): void
    {
        self::assertSame([], $this->findings(self::READ_ONLY."jobs:\n  t:\n    runs-on: ubuntu-latest\n    steps:\n      - env:\n          GH_TOKEN: \${{ secrets.GITHUB_TOKEN }}\n        run: gh pr view\n"));
    }

    public function testAStringInAnExpressionThatClosesBeforeItsBracesIsRead(): void
    {
        self::assertSame([], $this->findings(self::READ_ONLY."jobs:\n  t:\n    runs-on: ubuntu-latest\n    if: \${{ github.ref == 'refs/heads/main' && 'it''s' != '' }}\n    env:\n      K: \"plain \${{ github.sha }}\"\n"));
    }

    public function testPassingSecretsOnAsks(): void
    {
        self::assertSame(['6 unreadable', '7 secret'], $this->findings(self::READ_ONLY."jobs:\n  t:\n    uses: ./.github/workflows/build.yml\n    secrets: inherit\n"));
    }

    /** @return iterable<string, array{string}> */
    public static function triggersWithTheBaseRepositorysRights(): iterable
    {
        yield 'a scalar' => ['on: pull_request_target'];
        yield 'a flow list' => ['on: [push, workflow_run]'];
        yield 'a key' => ["on:\n  push:\n  issue_comment:\n    types: [created]"];
        yield 'a list item' => ["on:\n  - push\n  - pull_request_target"];
    }

    #[DataProvider('triggersWithTheBaseRepositorysRights')]
    public function testATriggerThatRunsWithTheBaseRepositorysRightsAsks(string $on): void
    {
        $findings = $this->findings($on."\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n");

        self::assertCount(1, $findings);
        self::assertStringEndsWith(' trigger', $findings[0]);
    }

    public function testTheTriggersThatRunWithThePushedBranchsRightsAreNotRisky(): void
    {
        self::assertSame([], $this->findings("on:\n  push:\n    branches: [main]\n    tags: ['v*', \"release-*\"]\n  pull_request:\n  merge_group:\n  workflow_dispatch:\n  schedule:\n    - cron: '0 3 * * 1'\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n"));
    }

    /** @return iterable<string, array{string, int}> */
    public static function formsTheScanDoesNotRead(): iterable
    {
        yield 'an anchor' => ["on: push\npermissions: {}\njobs:\n  t: &job\n    runs-on: ubuntu-latest\n", 4];
        yield 'an alias' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: *runner\n", 5];
        yield 'a merge key' => ["on: push\npermissions: {}\njobs:\n  t:\n    <<: {}\n    runs-on: ubuntu-latest\n", 5];
        yield 'an alias in a flow list' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: [ubuntu-latest, *runner]\n", 5];
        yield 'a comma inside a quoted flow list item' => ["on:\n  push:\n    tags: ['v1,v2']\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n", 3];
        yield 'a tag' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: !!str ubuntu-latest\n", 5];
        yield 'a flow mapping' => ["on: push\npermissions: {}\njobs: {t: {runs-on: self-hosted}}\n", 3];
        yield 'a quoted key' => ["on: push\npermissions: {}\njobs:\n  t:\n    'runs-on': self-hosted\n", 5];
        yield 'a complex key' => ["on: push\npermissions: {}\njobs:\n  t:\n    ? runs-on\n    : self-hosted\n", 5];
        yield 'a value on the next line' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on:\n      self-hosted\n", 6];
        yield 'a plain value over two lines' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n    if: github.ref == 'a' ||\n      github.ref == 'b'\n", 7];
        yield 'a quoted value over two lines' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n    if: \"a ||\n      b\"\n", 6];
        yield 'an expression over two lines' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n    steps:\n      - run: |\n          echo \${{\n          secrets.KEY }}\n", 8];
        yield 'a tab in the indentation' => ["on: push\npermissions: {}\njobs:\n  t:\n\truns-on: ubuntu-latest\n", 5];
        yield 'carriage returns as line breaks' => ["on: push\rpermissions: {}\rjobs:\r  t:\r    runs-on: self-hosted\r", 1];
        yield 'a line separator' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\u{2028}    runs-on: self-hosted\n", 5];
        yield 'a second document' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n---\njobs: {}\n", 6];
        yield 'a block scalar with an indentation indicator' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n    steps:\n      - run: |2\n          true\n", 7];
        yield 'a dash alone' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n    steps:\n      -\n        run: true\n", 7];
        yield 'closing braces inside a string of an expression' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo \"\${{ format('}}', secrets.DEPLOY_KEY) }}\"\n", 7];
        yield 'closing braces and a placeholder inside a string of an expression' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo \"\${{ format('}}{0}', secrets.DEPLOY_KEY) }}\"\n", 7];
        yield 'an escaped dollar in a double-quoted value' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n    steps:\n      - env:\n          K: \"\\x24{{ secrets.DEPLOY_KEY }}\"\n        run: true\n", 8];
        yield 'an escaped letter in a double-quoted value' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n    env:\n      K: \"\${{ sec\\u0072ets.DEPLOY_KEY }}\"\n", 7];
        yield 'an escaped letter in a double-quoted list item' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: [\"self-hoste\\u0064\"]\n", 5];
        yield 'a doubled quote in a single-quoted value' => ["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-latest\n    env:\n      K: '\${{ format(''}}'', secrets.DEPLOY_KEY) }}'\n", 7];
        yield 'invalid UTF-8' =>["on: push\npermissions: {}\njobs:\n  t:\n    runs-on: ubuntu-\xC3\x28latest\n", 0];
    }

    #[DataProvider('formsTheScanDoesNotRead')]
    public function testAFormTheScanDoesNotReadAsks(string $workflow, int $line): void
    {
        self::assertContains($line.' unreadable', $this->findings($workflow));
    }

    public function testADocumentMarkerBeforeTheWorkflowIsRead(): void
    {
        self::assertSame([], $this->findings("---\n".self::READ_ONLY."jobs:\n  t:\n    runs-on: ubuntu-latest\n"));
    }

    public function testAKeyInsideABlockScalarIsScriptTextButTheLineAfterItIsReadAgain(): void
    {
        self::assertSame(['11 not-hosted-runner'], $this->findings(self::READ_ONLY."jobs:\n  t:\n    steps:\n      - run: |\n          runs-on: self-hosted\n\n          permissions: write-all\n    runs-on: self-hosted\n"));
    }

    public function testALessIndentedLineEndsABlockScalarAsYamlDoes(): void
    {
        self::assertContains('10 unreadable', $this->findings(self::READ_ONLY."jobs:\n  t:\n    runs-on: ubuntu-latest\n    steps:\n      - run: |\n            true\n          permissions: write-all\n"));
    }

    public function testAFileThatCannotBeReadIsUnreadable(): void
    {
        $risk = new WorkflowRiskScan()->scan(new WorkflowFile('.github/workflows/ci.yml', null));

        self::assertSame(['0 unreadable'], $this->summaries($risk));
        self::assertSame('.github/workflows/ci.yml', $risk->findings[0]->path);
    }

    public function testTheFindingsOfEveryScannedWorkflowCount(): void
    {
        $risk = new WorkflowRiskScan()->scan(
            new WorkflowFile('head/ci.yml', self::READ_ONLY."jobs:\n  t:\n    runs-on: ubuntu-latest\n"),
            new WorkflowFile('main/ci.yml', self::READ_ONLY."jobs:\n  t:\n    runs-on: self-hosted\n"),
        );

        self::assertSame(['main/ci.yml'], array_map(static fn (WorkflowRiskFinding $finding): string => $finding->path, $risk->findings));
    }

    private function file(string $content): WorkflowFile
    {
        return new WorkflowFile('.github/workflows/ci.yml', $content);
    }

    /** @return list<string> */
    private function findings(string $content): array
    {
        return $this->summaries(new WorkflowRiskScan()->scan($this->file($content)));
    }

    /** @return list<string> */
    private function summaries(WorkflowRisk $risk): array
    {
        return array_map(static fn (WorkflowRiskFinding $finding): string => $finding->line.' '.$finding->kind->value, $risk->findings);
    }
}
