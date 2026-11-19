<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\ContainedTrigger;
use Intakt\Plugin\GitHubForge\Domain\HostedRunnerLabel;
use Intakt\Plugin\GitHubForge\Domain\SecretReferences;
use Intakt\Plugin\GitHubForge\Domain\TokenPermissions;
use Intakt\Plugin\GitHubForge\Domain\WorkflowBlockValue;
use Intakt\Plugin\GitHubForge\Domain\WorkflowEntry;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkflowFileScan::class)]
#[CoversClass(WorkflowReader::class)]
#[CoversClass(WorkflowInlineValue::class)]
#[CoversClass(WorkflowBlockValue::class)]
#[CoversClass(TokenPermissions::class)]
#[CoversClass(SecretReferences::class)]
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
final class WorkflowFileScanTest extends TestCase
{
    public function testAHostedRunnerWithAReadOnlyTokenAndNoSecretsHasNoFindings(): void
    {
        $findings = $this->scan(<<<'YAML'
            # The checks of a pull request.
            name: checks
            'on':
              push:
                branches: [main]
              pull_request:
            permissions:
              contents: read
              pull-requests: none
            jobs:
              check:
                runs-on: ubuntu-24.04
                steps:
                  - uses: actions/checkout@v4
                    with:
                      persist-credentials: false
                  - name: Check
                    run: |
                      bin/check
                      echo "done: ${{ github.sha }}"
                  - run: echo ${{ secrets.GITHUB_TOKEN }}
            YAML);

        self::assertSame([], $findings);
    }

    #[DataProvider('hostedLabels')]
    public function testEveryHostedRunnerLabelIsInsideTheEnvelope(HostedRunnerLabel $label): void
    {
        self::assertSame([], $this->scan($this->withJob('runs-on: '.$label->value)));
    }

    /**
     * @return iterable<string, array{HostedRunnerLabel}>
     */
    public static function hostedLabels(): iterable
    {
        foreach (HostedRunnerLabel::cases() as $label) {
            yield $label->value => [$label];
        }
    }

    public function testAnyOtherRunnerLabelIsARunnerFinding(): void
    {
        $findings = $this->scan($this->withJob('runs-on: build-box'));

        self::assertSame([['runner', 5, 'the job check runs on build-box, not a GitHub-hosted runner label']], $this->summary($findings));
    }

    public function testEachLabelOfAListedRunnerIsChecked(): void
    {
        $findings = $this->scan($this->withJob('runs-on: [self-hosted, ubuntu-24.04, linux]'));

        self::assertSame([['runner', 5], ['runner', 5]], $this->kindsAndLines($findings));
    }

    public function testARunnerListAtTheKeysOwnIndentationIsChecked(): void
    {
        $findings = $this->scan($this->withJob("runs-on:\n- self-hosted\n- ubuntu-latest"));

        self::assertSame([['runner', 6]], $this->kindsAndLines($findings));
    }

    public function testARunnerFromAVariableIsUnreadableEvenThoughTheVariableMayBeUnset(): void
    {
        $findings = $this->scan($this->withJob("runs-on: \${{ fromJSON(vars.CI_RUNS_ON || '\"ubuntu-24.04\"') }}"));

        self::assertSame([['unreadable', 5, 'the job check takes its runner from an expression the scan does not read, whose value can change without a commit']], $this->summary($findings));
    }

    public function testARunnerFromAMatrixIsUnreadable(): void
    {
        $findings = $this->scan($this->withJob("strategy:\n  matrix:\n    os: [ubuntu-latest]\nruns-on: \${{ matrix.os }}"));

        self::assertSame([['unreadable', 8]], $this->kindsAndLines($findings));
    }

    public function testAnExpressionInOneLabelOfAListedRunnerIsUnreadable(): void
    {
        $findings = $this->scan($this->withJob('runs-on: [ubuntu-24.04, "${{ vars.EXTRA_LABEL }}"]'));

        self::assertSame([['unreadable', 5]], $this->kindsAndLines($findings));
    }

    public function testARunnerGroupIsRisky(): void
    {
        $findings = $this->scan($this->withJob("runs-on:\n  group: large\n  labels: ubuntu-latest"));

        self::assertSame([['runner', 5]], $this->kindsAndLines($findings));
    }

    public function testAnExpressionInTheLabelsOfARunnerMappingIsUnreadable(): void
    {
        $findings = $this->scan($this->withJob("runs-on:\n  labels: \${{ vars.RUNNER }}"));

        self::assertSame([['runner', 5], ['unreadable', 6]], $this->kindsAndLines($findings));
    }

    public function testAnExpressionInAListedLabelOrTheGroupOfARunnerMappingIsUnreadable(): void
    {
        $findings = $this->scan($this->withJob("runs-on:\n  group: \${{ vars.GROUP }}\n  labels:\n    - ubuntu-latest\n    - \${{ vars.EXTRA }}"));

        self::assertSame([['runner', 5], ['unreadable', 6], ['unreadable', 9]], $this->kindsAndLines($findings));
    }

    public function testARunnerMappingWithoutExpressionsIsOnlyARunnerFinding(): void
    {
        $findings = $this->scan($this->withJob("runs-on:\n  group: large\n  labels: [ubuntu-latest, gpu]"));

        self::assertSame([['runner', 5]], $this->kindsAndLines($findings));
    }

    public function testABlockRunnerLabelWithATrailingBlankLineIsReportedOnOneLine(): void
    {
        $findings = $this->scan($this->withJob("runs-on: |\n  self-hosted\n\ntimeout-minutes: 5"));

        self::assertSame([['runner', 5, 'the job check runs on self-hosted, not a GitHub-hosted runner label']], $this->summary($findings));
    }

    public function testALineBreakInsideABlockRunnerLabelIsShownEscaped(): void
    {
        $findings = $this->scan($this->withJob("runs-on: >-\n  ubuntu\n  latest"));

        self::assertSame([['runner', 5, 'the job check runs on ubuntu\nlatest, not a GitHub-hosted runner label']], $this->summary($findings));
    }

    public function testABlockRunnerLabelIsLookedUpUntrimmed(): void
    {
        $findings = $this->scan($this->withJob("runs-on: |\n  ubuntu-latest"));

        self::assertSame([['runner', 5, 'the job check runs on ubuntu-latest, not a GitHub-hosted runner label']], $this->summary($findings));
    }

    public function testAJobLevelPermissionsKeyInAnotherCaseIsUnreadableOnItsLine(): void
    {
        $findings = $this->scan($this->withJob("runs-on: ubuntu-latest\nPermissions: write-all"));

        self::assertSame([['unreadable', 6, 'the key Permissions matches permissions only when case is ignored, and the scan does not settle how GitHub compares case']], $this->summary($findings));
    }

    public function testATopLevelJobsKeyInAnotherCaseIsUnreadableInsteadOfNamingNoJobs(): void
    {
        $findings = $this->scan("on: push\npermissions: read-all\nJobs:\n  check:\n    runs-on: ubuntu-latest\n");

        self::assertSame([['unreadable', 3, 'the key Jobs matches jobs only when case is ignored, and the scan does not settle how GitHub compares case']], $this->summary($findings));
    }

    public function testATopLevelTriggerKeyInAnotherCaseIsUnreadableInsteadOfNamingNoTrigger(): void
    {
        $findings = $this->scan("ON: pull_request_target\npermissions: read-all\njobs:\n  check:\n    runs-on: ubuntu-latest\n");

        self::assertSame([['unreadable', 1, 'the key ON matches on only when case is ignored, and the scan does not settle how GitHub compares case']], $this->summary($findings));
    }

    public function testATopLevelPermissionsKeyInAnotherCaseIsUnreadable(): void
    {
        $findings = $this->scan("on: push\nPERMISSIONS: write-all\njobs:\n  check:\n    runs-on: ubuntu-latest\n    permissions: read-all\n");

        self::assertSame([['unreadable', 2]], $this->kindsAndLines($findings));
    }

    public function testARunnerKeyInAnotherCaseIsUnreadableInsteadOfNamingNoRunner(): void
    {
        $findings = $this->scan($this->withJob('RUNS-ON: self-hosted'));

        self::assertSame([['unreadable', 5, 'the key RUNS-ON matches runs-on only when case is ignored, and the scan does not settle how GitHub compares case']], $this->summary($findings));
    }

    public function testACalledWorkflowKeyInAnotherCaseIsUnreadable(): void
    {
        $findings = $this->scan($this->withJob("runs-on: ubuntu-latest\nUses: ./.github/workflows/build.yml"));

        self::assertSame([['unreadable', 6]], $this->kindsAndLines($findings));
    }

    public function testAPassedSecretsKeyInAnotherCaseIsUnreadableAndStillASecret(): void
    {
        $findings = $this->scan($this->withJob("runs-on: ubuntu-latest\nSecrets: inherit"));

        self::assertSame([['unreadable', 6], ['secret', 6]], $this->kindsAndLines($findings));
    }

    public function testAKeyInAnotherCaseIsUnreadableEvenBesideTheExactSpelling(): void
    {
        $findings = $this->scan($this->withJob("runs-on: ubuntu-latest\npermissions: read-all\nPermissions: write-all"));

        self::assertSame([['unreadable', 7]], $this->kindsAndLines($findings));
    }

    public function testEveryCaseVariantOfAKeyIsUnreadableOnItsOwnLine(): void
    {
        $findings = $this->scan($this->withJob("runs-on: ubuntu-latest\npermissions: read-all\nPermissions: read-all\nPERMISSIONS: read-all"));

        self::assertSame([['unreadable', 7], ['unreadable', 8]], $this->kindsAndLines($findings));
    }

    public function testKeysTheScanDoesNotReadByNameMayUseAnyCase(): void
    {
        $findings = $this->scan("Name: checks\non: push\npermissions: read-all\njobs:\n  Check:\n    runs-on: ubuntu-latest\n    Timeout-Minutes: 5\n");

        self::assertSame([], $findings);
    }

    public function testAJobWithoutARunnerIsUnreadable(): void
    {
        $findings = $this->scan($this->withJob('timeout-minutes: 5'));

        self::assertSame([['unreadable', 5]], $this->kindsAndLines($findings));
    }

    public function testAJobThatCallsAnotherWorkflowIsUnreadable(): void
    {
        $findings = $this->scan($this->withJob('uses: ./.github/workflows/build.yml'));

        self::assertSame([['unreadable', 5, 'the job check calls another workflow, which this scan does not read']], $this->summary($findings));
    }

    public function testAWritePermissionIsAPermissionsFinding(): void
    {
        $findings = $this->scan($this->withJob("runs-on: ubuntu-latest\npermissions:\n  contents: read\n  issues: write"));

        self::assertSame([['permissions', 8, 'the token may write issues']], $this->summary($findings));
    }

    public function testWriteAllIsAPermissionsFinding(): void
    {
        $findings = $this->scan($this->workflow("permissions: write-all\n", "  check:\n    runs-on: ubuntu-latest"));

        self::assertSame([['permissions', 2]], $this->kindsAndLines($findings));
    }

    public function testReadAllAndAnEmptyMappingAreReadOnly(): void
    {
        $findings = $this->scan($this->workflow("permissions: read-all\n", "  check:\n    runs-on: ubuntu-latest\n    permissions: {}"));

        self::assertSame([], $findings);
    }

    public function testAFlowMappingOfPermissionsIsChecked(): void
    {
        $findings = $this->scan($this->workflow("permissions: {contents: read, packages: write}\n", "  check:\n    runs-on: ubuntu-latest"));

        self::assertSame([['permissions', 2, 'the token may write packages']], $this->summary($findings));
    }

    public function testAJobWithoutPermissionsAnywhereLeavesTheTokenToTheRepositoryDefault(): void
    {
        $findings = $this->scan($this->workflow('', "  check:\n    runs-on: ubuntu-latest"));

        self::assertSame([['permissions', 3]], $this->kindsAndLines($findings));
    }

    public function testAJobWithItsOwnReadPermissionsNeedsNoWorkflowPermissions(): void
    {
        $findings = $this->scan($this->workflow('', "  check:\n    runs-on: ubuntu-latest\n    permissions:\n      contents: read"));

        self::assertSame([], $findings);
    }

    public function testAPermissionLevelTheScanDoesNotKnowIsUnreadable(): void
    {
        $findings = $this->scan($this->workflow("permissions:\n  contents: \${{ inputs.level }}\n", "  check:\n    runs-on: ubuntu-latest"));

        self::assertSame([['unreadable', 3]], $this->kindsAndLines($findings));
    }

    public function testASecretInAOneLineValueIsASecretFinding(): void
    {
        $findings = $this->scan($this->withJob("runs-on: ubuntu-latest\nenv:\n  KEY: \${{ secrets.DEPLOY_KEY }}"));

        self::assertSame([['secret', 7, 'reads a repository secret (secrets.DEPLOY_KEY)']], $this->summary($findings));
    }

    public function testASecretIsFoundInAnyCase(): void
    {
        $findings = $this->scan($this->withJob("runs-on: ubuntu-latest\nenv:\n  KEY: \${{ SECRETS['deploy'] }}"));

        self::assertSame([['secret', 7]], $this->kindsAndLines($findings));
    }

    public function testTheJobsOwnTokenIsNoSecretFinding(): void
    {
        $findings = $this->scan($this->withJob("runs-on: ubuntu-latest\nenv:\n  TOKEN: \${{ Secrets.github_token }}"));

        self::assertSame([], $findings);
    }

    public function testPassingSecretsToAnotherWorkflowIsASecretFinding(): void
    {
        $findings = $this->scan($this->withJob("uses: ./.github/workflows/build.yml\nsecrets: inherit"));

        self::assertSame([['unreadable', 5], ['secret', 6]], $this->kindsAndLines($findings));
    }

    #[DataProvider('blockHeaders')]
    public function testASecretInABlockValueIsASecretFinding(string $header): void
    {
        $findings = $this->scan($this->withJob("runs-on: ubuntu-latest\nsteps:\n  - if: {$header}\n      github.event_name == 'push' &&\n      secrets.KEY != ''\n    run: echo"));

        self::assertSame([['secret', 7, 'reads a repository secret (secrets.KEY)']], $this->summary($findings));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockHeaders(): iterable
    {
        foreach (['|', '|-', '|+', '>', '>-', '>+', '| # why'] as $header) {
            yield $header => [$header];
        }
    }

    public function testABlockValueKeepsItsBlankLinesAndEndsAtTheNextKey(): void
    {
        $findings = $this->scan($this->withJob("runs-on: ubuntu-latest\nsteps:\n  - run: |\n      echo one\n\n      echo two\n    env:\n      KEY: \${{ secrets.KEY }}"));

        self::assertSame([['secret', 12]], $this->kindsAndLines($findings));
    }

    public function testKeysInsideABlockValueAreText(): void
    {
        $findings = $this->scan($this->withJob("runs-on: ubuntu-latest\nsteps:\n  - run: |\n      runs-on: self-hosted\n      permissions: write-all"));

        self::assertSame([], $findings);
    }

    public function testATriggerWithTheBaseRepositorysRightsIsATriggerFinding(): void
    {
        $findings = $this->scan(<<<'YAML'
            on:
              pull_request_target:
                types: [opened]
            permissions: read-all
            jobs:
              check:
                runs-on: ubuntu-latest
            YAML);

        self::assertSame([['trigger', 2, 'the trigger pull_request_target may run with the base repository\'s rights']], $this->summary($findings));
    }

    public function testATriggerListAtTheKeysOwnIndentationKeepsItsItemsUnderOn(): void
    {
        $findings = $this->scan(<<<'YAML'
            on:
            - push
            - pull_request_target
            permissions: read-all
            jobs:
              check:
                runs-on: ubuntu-latest
            YAML);

        self::assertSame([['trigger', 3]], $this->kindsAndLines($findings));
    }

    public function testATriggerInAFlowListIsChecked(): void
    {
        $findings = $this->scan($this->workflow("permissions: read-all\n", "  check:\n    runs-on: ubuntu-latest", '[push, workflow_run]'));

        self::assertSame([['trigger', 1]], $this->kindsAndLines($findings));
    }

    public function testASingleTriggerIsChecked(): void
    {
        $findings = $this->scan($this->workflow("permissions: read-all\n", "  check:\n    runs-on: ubuntu-latest", 'issue_comment'));

        self::assertSame([['trigger', 1]], $this->kindsAndLines($findings));
    }

    public function testAWorkflowWithoutTriggersOrJobsIsUnreadable(): void
    {
        $findings = $this->scan("name: nothing\npermissions: read-all\n");

        self::assertSame([['unreadable', 1], ['unreadable', 1]], $this->kindsAndLines($findings));
    }

    public function testWindowsLineEndingsAreRead(): void
    {
        $findings = $this->scan(str_replace("\n", "\r\n", $this->withJob('runs-on: build-box')));

        self::assertSame([['runner', 5]], $this->kindsAndLines($findings));
    }

    #[DataProvider('unreadableForms')]
    public function testAFormTheScanDoesNotReadIsUnreadable(string $workflow, int $line): void
    {
        $findings = $this->scan($workflow);

        self::assertSame([['unreadable', $line]], $this->kindsAndLines($findings));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function unreadableForms(): iterable
    {
        $job = "on: push\npermissions: read-all\njobs:\n  check:\n";

        yield 'an empty file' => ['', 1];
        yield 'an alias' => ["defaults: &runner ubuntu-latest\n".$job.'    runs-on: *runner', 1];
        yield 'a merge key' => [$job."    <<: {runs-on: self-hosted}\n", 5];
        yield 'a tag' => [$job.'    runs-on: !custom self-hosted', 5];
        yield 'a tab in the indentation' => [$job."\truns-on: ubuntu-latest", 5];
        yield 'a plain value over two lines' => [$job."    runs-on: ubuntu-latest\n      self-hosted", 6];
        yield 'a quoted value over two lines' => [$job."    runs-on: \"ubuntu-latest\n      \"", 5];
        yield 'an escape in a double-quoted value' => [$job.'    runs-on: "\x75buntu-latest"', 5];
        yield 'a nested flow value' => [$job.'    runs-on: [[self-hosted]]', 5];
        yield 'a flow value with a quoted expression' => [$job.'    runs-on: ["${{ vars.RUNNER }}"]', 5];
        yield 'a block indentation indicator' => [$job."    runs-on: ubuntu-latest\n    env:\n      A: |2\n          x", 7];
        yield 'a block line indented less than its first line' => [$job."    runs-on: ubuntu-latest\n    env:\n      A: |\n          x\n        \${{ secrets.KEY }}", 9];
        yield 'leading blank lines deeper than the block text' => [$job."    runs-on: ubuntu-latest\n    env:\n      A: |\n            \n          x", 9];
        yield 'a key that appears twice' => [$job."    runs-on: ubuntu-latest\n    runs-on: self-hosted", 6];
        yield 'a line that belongs to no key' => [$job."    runs-on: ubuntu-latest\n      steps: []", 6];
        yield 'a second document' => [$job."    runs-on: ubuntu-latest\n---\njobs: {}", 6];
        yield 'a complex key' => [$job."    ? runs-on\n    : ubuntu-latest", 5];
        yield 'a nested key in a value' => [$job.'    runs-on: ubuntu: latest', 5];
        yield 'a control character' => [$job."    runs-on: ubuntu-latest\x0B", 5];
        yield 'a YAML 1.1 line break' => [$job."    runs-on: ubuntu-latest\u{2028}    permissions: write-all", 5];
        yield 'text that is not UTF-8' => [$job."    runs-on: ubuntu-latest\xFF", 1];
        yield 'a workflow that is a list' => ["- on: push\n", 1];
        yield 'a workflow indented from the margin' => ['  on: push', 1];
    }

    public function testADocumentStartAndAByteOrderMarkAreRead(): void
    {
        $findings = $this->scan("\u{FEFF}---\n".$this->withJob('runs-on: ubuntu-latest'));

        self::assertSame([], $findings);
    }

    /**
     * @return list<WorkflowFinding>
     */
    private function scan(string $workflow): array
    {
        return new WorkflowFileScan(new WorkflowReader(new WorkflowInlineValue(), new WorkflowBlockValue()), new TokenPermissions(), new SecretReferences())->scan($workflow);
    }

    private function withJob(string $job): string
    {
        return $this->workflow("permissions: read-all\n", "  check:\n".preg_replace('/^/m', '    ', $job));
    }

    private function workflow(string $permissions, string $jobs, string $on = 'push'): string
    {
        return "on: {$on}\n{$permissions}jobs:\n{$jobs}\n";
    }

    /**
     * @param list<WorkflowFinding> $findings
     *
     * @return list<array{string, int, string}>
     */
    private function summary(array $findings): array
    {
        return array_map(static fn (WorkflowFinding $finding): array => [$finding->risk->value, $finding->line, $finding->detail], $findings);
    }

    /**
     * @param list<WorkflowFinding> $findings
     *
     * @return list<array{string, int}>
     */
    private function kindsAndLines(array $findings): array
    {
        return array_map(static fn (WorkflowFinding $finding): array => [$finding->risk->value, $finding->line], $findings);
    }
}
