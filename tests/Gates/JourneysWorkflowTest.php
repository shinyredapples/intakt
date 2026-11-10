<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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

        self::assertArrayNotHasKey('permissions', $workflow['jobs']['journeys']);
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

    public function testTheAlarmRunsAfterTheJourneysOnlyWhenTheirStepFailed(): void
    {
        $jobs = $this->workflow()['jobs'];

        self::assertSame('journeys', $jobs['alarm']['needs']);
        self::assertSame("failure() && needs.journeys.outputs.outcome == 'failure'", $jobs['alarm']['if']);
        self::assertSame('${{ steps.journeys.outcome }}', $jobs['journeys']['outputs']['outcome']);
    }

    public function testOnlyTheLastAlarmStepHasTheTokenInItsEnvironment(): void
    {
        $workflow = $this->workflow();

        self::assertSame(['jobs.alarm.steps.'.array_key_last($workflow['jobs']['alarm']['steps'])], $this->placesHoldingTheToken($workflow));
    }

    /**
     * @return array<string, mixed>
     */
    private function workflow(): array
    {
        self::assertFileExists(self::WORKFLOW);

        /** @var array<string, mixed> */
        return Yaml::parseFile(self::WORKFLOW);
    }

    /**
     * @param array<string, mixed> $workflow
     *
     * @return list<string>
     */
    private function placesHoldingTheToken(array $workflow): array
    {
        $places = [];
        if (isset($workflow['env']['GH_TOKEN'])) {
            $places[] = 'env';
        }
        foreach ($workflow['jobs'] as $name => $job) {
            if (isset($job['env']['GH_TOKEN'])) {
                $places[] = 'jobs.'.$name;
            }
            foreach ($job['steps'] ?? [] as $index => $step) {
                if (isset($step['env']['GH_TOKEN'])) {
                    $places[] = 'jobs.'.$name.'.steps.'.$index;
                }
            }
        }

        return $places;
    }
}
