<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * @phpstan-type Step array{name?: string, id?: string, if?: string, run?: string, uses?: string, with?: array<string, string|int>}
 * @phpstan-type Job array{if?: string, needs?: string, permissions?: array<string, string>, steps?: list<Step>}
 * @phpstan-type WorkflowFile array{permissions: array<string, string>, jobs: array<string, Job>}
 */
#[CoversNothing]
final class CiMainJobTest extends TestCase
{
    private const string WORKFLOW = __DIR__.'/../../.github/workflows/ci.yml';

    private const string INTO_MAIN = "github.event_name == 'pull_request' && github.base_ref == 'main'";

    private const string GUARD = "(github.event.pull_request.head.repo.full_name == github.repository || github.event_name == 'push')";

    public function testTheMainJobRunsOnAPushOnlyAndOnlyReadsActions(): void
    {
        $job = $this->job('main');

        self::assertSame("github.event_name == 'push'", $job['if'] ?? null);
        self::assertSame(['contents' => 'read', 'actions' => 'read'], $job['permissions'] ?? null);
        self::assertSame(['contents' => 'read'], $this->workflow()['permissions']);
    }

    public function testTheMainJobAsksAboutThePushedCommitAndItsTree(): void
    {
        $step = $this->step('main', 'passed');

        self::assertStringContainsString('.github/ci/pr-run-passed.sh "$GITHUB_SHA" "$(git rev-parse "$GITHUB_SHA^{tree}")"', $step['run'] ?? '');
    }

    public function testThePersistenceJobRunsBehindAStatusCheckOnASucceededChangesJob(): void
    {
        $job = $this->job('persistence');

        self::assertSame('changes', $job['needs'] ?? null);
        self::assertSame("\${{ !cancelled() && !failure() && needs.changes.result == 'success' && ".self::GUARD.' }}', $job['if'] ?? null);
    }

    public function testEveryOtherJobRunsBehindAStatusCheckUnlessMainSkippedTheFullRun(): void
    {
        foreach (['check', 'journeys', 'changes', 'image'] as $name) {
            $job = $this->job($name);

            self::assertSame('main', $job['needs'] ?? null, $name);
            self::assertSame("\${{ !cancelled() && !failure() && needs.main.outputs.full != 'false' && ".self::GUARD.' }}', $job['if'] ?? null, $name);
        }
    }

    public function testAPullRequestRunIntoMainRecordsTheTreeOfTheMergeItTestedAfterTheGates(): void
    {
        $steps = $this->job('check')['steps'] ?? [];
        $names = array_map(static fn (array $step): string => $step['name'] ?? '', $steps);
        $record = $this->step('check', 'tested-tree');
        $upload = $steps[count($steps) - 1];

        self::assertGreaterThan(array_search('Run the gates', $names, true), array_search('Record the tested tree', $names, true));
        self::assertSame(self::INTO_MAIN, $record['if'] ?? null);
        self::assertStringContainsString('git rev-parse "$GITHUB_SHA^{tree}"', $record['run'] ?? '');
        self::assertSame(self::INTO_MAIN, $upload['if'] ?? null);
        self::assertMatchesRegularExpression('/^actions\/upload-artifact@[0-9a-f]{40}$/', $upload['uses'] ?? '');
        self::assertSame('main-tested-tree-${{ steps.tested-tree.outputs.tree }}', $upload['with']['name'] ?? null);
        self::assertTrue($upload['with']['overwrite'] ?? null);
    }

    /**
     * @return Step
     */
    private function step(string $job, string $id): array
    {
        foreach ($this->job($job)['steps'] ?? [] as $step) {
            if ($id === ($step['id'] ?? null)) {
                return $step;
            }
        }

        self::fail(sprintf('The %s job has no step %s.', $job, $id));
    }

    /**
     * @return Job
     */
    private function job(string $name): array
    {
        $jobs = $this->workflow()['jobs'];
        self::assertArrayHasKey($name, $jobs, sprintf('ci.yml has no %s job.', $name));

        return $jobs[$name];
    }

    /**
     * @return WorkflowFile
     */
    private function workflow(): array
    {
        /** @var WorkflowFile $workflow */
        $workflow = Yaml::parseFile(self::WORKFLOW);

        return $workflow;
    }
}
