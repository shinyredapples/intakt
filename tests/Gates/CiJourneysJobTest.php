<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * @phpstan-type Step array{run?: string}
 * @phpstan-type Job array{if?: string, runs-on?: string, timeout-minutes?: int, permissions?: array<string, string>, steps?: list<Step>}
 * @phpstan-type WorkflowFile array{on: array<string, mixed>, jobs: array<string, Job>}
 */
#[CoversNothing]
final class CiJourneysJobTest extends TestCase
{
    private const string WORKFLOW = __DIR__.'/../../.github/workflows/ci.yml';

    public function testTheCiWorkflowRunsOnEveryPullRequestAndEveryPushToMain(): void
    {
        $on = $this->workflow()['on'];

        self::assertSame(['pull_request', 'push'], array_keys($on));
        self::assertNull($on['pull_request']);
        self::assertSame(['branches' => ['main']], $on['push']);
    }

    public function testTheJourneysJobRunsOnlyBehindTheForkGuard(): void
    {
        self::assertSame("\${{ !cancelled() && needs.main.outputs.full != 'false' && (github.event.pull_request.head.repo.full_name == github.repository || github.event_name == 'push') }}", $this->job()['if'] ?? null);
    }

    public function testTheJourneysJobRunsWhereTheCheckJobRuns(): void
    {
        $runsOn = $this->job()['runs-on'] ?? null;

        self::assertSame('${{ fromJSON(vars.CI_RUNS_ON || \'"ubuntu-24.04"\') }}', $runsOn);
        self::assertSame($this->workflow()['jobs']['check']['runs-on'] ?? null, $runsOn);
    }

    public function testTheJourneysJobHasTheNightlyJobsTimeout(): void
    {
        self::assertSame(40, $this->job()['timeout-minutes'] ?? null);
    }

    public function testAStepOfTheJourneysJobRunsTheJourneys(): void
    {
        $runs = array_filter(
            $this->job()['steps'] ?? [],
            static fn (array $step): bool => str_contains($step['run'] ?? '', 'bin/check --journeys'),
        );

        self::assertCount(1, $runs);
    }

    public function testTheJourneysJobSetsNoPermissionsOfItsOwn(): void
    {
        self::assertArrayNotHasKey('permissions', $this->job());
    }

    /**
     * @return Job
     */
    private function job(): array
    {
        $jobs = $this->workflow()['jobs'];
        self::assertArrayHasKey('journeys', $jobs, 'ci.yml has no journeys job.');

        return $jobs['journeys'];
    }

    /**
     * @return WorkflowFile
     */
    private function workflow(): array
    {
        self::assertFileExists(self::WORKFLOW);

        /** @var WorkflowFile $workflow */
        $workflow = Yaml::parseFile(self::WORKFLOW);

        return $workflow;
    }
}
