<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

/**
 * @phpstan-type Step array{id?: string, run?: string, env?: array<string, string>}
 * @phpstan-type Job array{permissions?: array<string, string>, needs?: string|list<string>, if?: string, steps?: list<Step>}
 * @phpstan-type WorkflowFile array{on: array<string, mixed>, concurrency: array{group: string}, permissions: array<string, string>, jobs: array<string, Job>}
 */
// The nightly and on-demand full run of ci.yml on main and its failure issue; the steps run from the workflow file itself.
#[CoversNothing]
final class NightlyCiTest extends SandboxTestCase
{
    private const string WORKFLOW = __DIR__.'/../../.github/workflows/ci.yml';

    private const string SCRIPT = __DIR__.'/../../.github/ci/nightly-failed-issue.sh';

    // A fake git for the decision steps: only the first parent answers, so a step that asks about a merge base fails.
    private const string FAKE_GIT = <<<'SH'
        #!/bin/sh
        if [ "$1 $2" = 'rev-parse HEAD~1' ]; then echo parent; exit 0; fi
        exit 2
        SH;

    // A fake gh: the issues call answers from issues.json through --jq, the issue commands write what they were asked to calls.
    private const string FAKE_GH = <<<'SH'
        #!/bin/sh
        set -eu
        if [ -e fail ]; then echo 'gh: Bad credentials (HTTP 401)' >&2; exit 1; fi
        case "$1 $2" in
          'api --paginate')
            [ "$3" = 'repos/example/intakt/issues?state=open&per_page=100' ] || exit 3
            jq -r "$5" issues.json ;;
          'issue comment') echo "comment $3 $5 $7" >> calls ;;
          'issue create') echo "create $6 / $8" >> calls ;;
          *) exit 3 ;;
        esac
        SH;

    private const string RUN = 'https://github.com/example/intakt/actions/runs/9';

    public function testTheWorkflowAddsANightlyScheduleAndARunOnDemand(): void
    {
        $workflow = $this->workflow();

        self::assertSame(['pull_request', 'push', 'schedule', 'workflow_dispatch'], array_keys($workflow['on']));
        self::assertSame(['branches' => ['main']], $workflow['on']['push']);
        self::assertSame([['cron' => '43 3 * * *']], $workflow['on']['schedule']);
        self::assertNull($workflow['on']['workflow_dispatch']);
    }

    public function testAPushToMainNeverCancelsTheNightlyRun(): void
    {
        self::assertSame(
            "ci-\${{ github.ref }}\${{ (github.event_name == 'schedule' || github.event_name == 'workflow_dispatch') && '-nightly' || '' }}",
            $this->workflow()['concurrency']['group'],
        );
    }

    public function testOnlyTheNightlyFailedJobMayWriteIssues(): void
    {
        $workflow = $this->workflow();
        $writers = array_keys(array_filter(
            $workflow['jobs'],
            static fn (array $job): bool => 'write' === ($job['permissions']['issues'] ?? null),
        ));

        self::assertSame(['contents' => 'read'], $workflow['permissions']);
        self::assertSame(['nightly-failed'], $writers);
        self::assertSame(['contents' => 'read', 'issues' => 'write'], $workflow['jobs']['nightly-failed']['permissions'] ?? null);
    }

    public function testTheIssueOpensOnlyAfterAFailedNightlyOrOnDemandRunOnMain(): void
    {
        $job = $this->workflow()['jobs']['nightly-failed'];

        self::assertSame(['check', 'journeys', 'changes', 'persistence', 'image'], $job['needs'] ?? null);
        self::assertSame("failure() && (github.event_name == 'schedule' || github.event_name == 'workflow_dispatch') && github.ref == 'refs/heads/main'", $job['if'] ?? null);
        self::assertSame('.github/ci/nightly-failed-issue.sh "$RUN_LINK"', $job['steps'][1]['run'] ?? null);
    }

    /** @return iterable<string, array{string}> */
    public static function fullRunEvents(): iterable
    {
        yield 'the nightly schedule' => ['schedule'];
        yield 'a run on demand' => ['workflow_dispatch'];
        yield 'a push to main' => ['push'];
    }

    #[DataProvider('fullRunEvents')]
    public function testEveryRunOutsideAPullRequestRunsTheJourneysTheEnginesAndTheUpgradeCheck(string $event): void
    {
        self::assertSame("needed=yes\n", $this->decide('journeys', 'journeys', $event));
        self::assertSame("persistence=true\n", $this->decide('changes', 'paths', $event));
        self::assertSame("base=parent\nneeded=yes\nimage=yes\n", $this->decide('image', 'upgrade', $event));
    }

    public function testAFailedRunOpensTheIssueWhenNoneIsOpen(): void
    {
        $sandbox = $this->sandboxWithIssues([
            ['number' => 3, 'title' => 'Nightly CI failed on main', 'pull_request' => ['url' => 'x']],
            ['number' => 4, 'title' => 'Nightly CI failed on main, again'],
        ]);

        $result = $this->report($sandbox, self::RUN);

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame('create Nightly CI failed on main / The nightly full run of ci.yml on main failed: '.self::RUN."\n", file_get_contents($sandbox->root.'/calls'));
    }

    public function testAFailedRunCommentsOnTheOpenIssue(): void
    {
        $sandbox = $this->sandboxWithIssues([['number' => 4, 'title' => 'Other'], ['number' => 5, 'title' => 'Nightly CI failed on main']]);

        $result = $this->report($sandbox, self::RUN);

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame('comment 5 example/intakt The nightly full run of ci.yml on main failed: '.self::RUN."\n", file_get_contents($sandbox->root.'/calls'));
    }

    public function testAFailingIssuesCallFailsTheJobAndWritesNothing(): void
    {
        $sandbox = $this->sandboxWithIssues([]);
        $sandbox->write('fail', '');

        $result = $this->report($sandbox, self::RUN);

        self::assertFalse($result->succeeded());
        self::assertFileDoesNotExist($sandbox->root.'/calls');
    }

    public function testAMissingRunLinkIsAUsageError(): void
    {
        $sandbox = $this->sandboxWithIssues([]);

        $result = $this->report($sandbox, '');

        self::assertSame(2, $result->exitCode);
        self::assertFileDoesNotExist($sandbox->root.'/calls');
    }

    private function decide(string $job, string $id, string $event): string
    {
        $steps = array_values(array_filter($this->workflow()['jobs'][$job]['steps'] ?? [], static fn (array $step): bool => $id === ($step['id'] ?? null)));
        self::assertCount(1, $steps);
        $sandbox = $this->emptySandbox();
        $sandbox->write('bin/git', self::FAKE_GIT);

        $result = $sandbox->runScript(
            'chmod +x bin/git; PATH="$PWD/bin:$PATH"; '.($steps[0]['run'] ?? ''),
            ['EVENT' => $event, 'BASE_REF' => '', 'HEAD_SHA' => '', 'GITHUB_OUTPUT' => $sandbox->root.'/output'],
        );

        self::assertTrue($result->succeeded(), $result->errors);

        return (string) file_get_contents($sandbox->root.'/output');
    }

    /** @param list<array<string, mixed>> $issues */
    private function sandboxWithIssues(array $issues): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('bin/gh', self::FAKE_GH);
        $sandbox->write('issues.json', json_encode($issues, \JSON_THROW_ON_ERROR));

        return $sandbox;
    }

    private function report(Sandbox $sandbox, string $runLink): ProcessResult
    {
        return $sandbox->runScript(
            'chmod +x bin/gh; PATH="$PWD/bin:$PATH"; sh '.escapeshellarg(self::SCRIPT).' '.escapeshellarg($runLink),
            ['GITHUB_REPOSITORY' => 'example/intakt'],
        );
    }

    /** @return WorkflowFile */
    private function workflow(): array
    {
        /** @var WorkflowFile $workflow */
        $workflow = Yaml::parseFile(self::WORKFLOW);

        return $workflow;
    }
}
