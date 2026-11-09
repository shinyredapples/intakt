<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
final class JourneysWorkflowTest extends TestCase
{
    public function testTheWorkflowRunsOnlyNightlyAndOnDemand(): void
    {
        self::assertSame(['schedule', 'workflow_dispatch'], array_keys($this->workflow()['on']));
    }

    public function testTheJobThatRunsTheJourneysCannotWriteIssues(): void
    {
        $journeys = $this->workflow()['jobs']['journeys'];

        self::assertSame(['contents' => 'read'], $journeys['permissions'] ?? $this->workflow()['permissions']);
    }

    public function testTheAlarmRunsOnGitHubsImageWhichHasGhWhateverTheRunnerVariableSays(): void
    {
        self::assertSame('ubuntu-24.04', $this->workflow()['jobs']['alarm']['runs-on']);
    }

    /**
     * @return array{on: array<string, mixed>, permissions: array<string, string>, jobs: array{journeys: array{permissions?: array<string, string>}, alarm: array{runs-on: string}}}
     */
    private function workflow(): array
    {
        /** @var array{on: array<string, mixed>, permissions: array<string, string>, jobs: array{journeys: array{permissions?: array<string, string>}, alarm: array{runs-on: string}}} $workflow */
        $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/journeys.yml');

        return $workflow;
    }
}
