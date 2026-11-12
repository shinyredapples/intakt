<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * @phpstan-type Step array{env?: array<string, string>, if?: string, uses?: string, with?: array<string, string>}
 * @phpstan-type Job array{permissions?: array<string, string>, needs?: string|list<string>, if?: string, outputs?: array<string, string>, env?: array<string, string>, steps?: list<Step>}
 * @phpstan-type WorkflowFile array{on: array<string, mixed>, permissions: array<string, string>, env?: array<string, string>, jobs: array<string, Job>}
 */
#[CoversNothing]
final class JourneysWorkflowTest extends TestCase
{
    private const string WORKFLOW = __DIR__.'/../../.github/workflows/journeys.yml';

    public function testTheWorkflowRunsOnlyOnScheduleAndOnDemand(): void
    {
        $workflow = $this->workflow();

        self::assertSame(['schedule', 'workflow_dispatch'], array_keys($workflow['on']));
    }

    public function testTheWorkflowTokenIsReadOnlyByDefault(): void
    {
        $workflow = $this->workflow();

        self::assertSame(['contents' => 'read'], $workflow['permissions']);
    }

    public function testTheJourneysJobSetsNoPermissionsOfItsOwn(): void
    {
        $workflow = $this->workflow();

        self::assertArrayNotHasKey('permissions', $workflow['jobs']['journeys'] ?? []);
    }

    public function testOnlyTheAlarmJobMayWriteIssues(): void
    {
        $workflow = $this->workflow();

        $writers = array_keys(array_filter(
            $workflow['jobs'],
            static fn (array $job): bool => 'write' === ($job['permissions']['issues'] ?? null),
        ));

        self::assertSame(['alarm'], $writers);
    }

    public function testTheAlarmRunsAfterTheJourneysOnlyWhenTheirStepFailedOrTimedOut(): void
    {
        $jobs = $this->workflow()['jobs'];

        self::assertSame('journeys', $jobs['alarm']['needs'] ?? null);
        self::assertSame("failure() && (needs.journeys.outputs.outcome == 'failure' || needs.journeys.outputs.outcome == 'cancelled')", $jobs['alarm']['if'] ?? null);
        self::assertSame('${{ steps.journeys.outcome }}', $jobs['journeys']['outputs']['outcome'] ?? null);
    }

    public function testTheOutputIsUploadedWhenTheJourneysStepFailedOrTimedOut(): void
    {
        $uploads = array_values(array_filter(
            $this->workflow()['jobs']['journeys']['steps'] ?? [],
            static fn (array $step): bool => 'journeys-output' === ($step['with']['name'] ?? null)
                && str_starts_with($step['uses'] ?? '', 'actions/upload-artifact@'),
        ));

        self::assertCount(1, $uploads);
        self::assertSame("always() && (steps.journeys.outcome == 'failure' || steps.journeys.outcome == 'cancelled')", $uploads[0]['if'] ?? null);
    }

    public function testOnlyTheLastAlarmStepHasTheTokenInItsEnvironment(): void
    {
        $workflow = $this->workflow();
        $lastStep = array_key_last($workflow['jobs']['alarm']['steps'] ?? []);

        self::assertSame(['jobs.alarm.steps.'.$lastStep], $this->placesHoldingTheToken($workflow));
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

    /**
     * @param WorkflowFile $workflow
     *
     * @return list<string>
     */
    private function placesHoldingTheToken(array $workflow): array
    {
        $places = isset($workflow['env']['GH_TOKEN']) ? ['env'] : [];

        foreach ($workflow['jobs'] as $name => $job) {
            $places = [...$places, ...$this->placesInJob('jobs.'.$name, $job)];
        }

        return $places;
    }

    /**
     * @param Job $job
     *
     * @return list<string>
     */
    private function placesInJob(string $path, array $job): array
    {
        $places = isset($job['env']['GH_TOKEN']) ? [$path] : [];

        foreach ($job['steps'] ?? [] as $index => $step) {
            if (isset($step['env']['GH_TOKEN'])) {
                $places[] = $path.'.steps.'.$index;
            }
        }

        return $places;
    }
}
