<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * @phpstan-type Step array{id?: string, if?: string, run?: string}
 * @phpstan-type Job array{runs-on?: string, steps?: list<Step>}
 */
#[CoversNothing]
final class ArmImageWorkflowTest extends TestCase
{
    private const string WORKFLOW = __DIR__.'/../../.github/workflows/image-arm64.yml';

    public function testTheArm64ImageIsBuiltOnAPushToMainAndOnDemandButNeverForAPullRequest(): void
    {
        self::assertSame(['push' => ['branches' => ['main']], 'workflow_dispatch' => null], $this->workflow()['on'] ?? null);
    }

    public function testItsRunnerComesFromCiArmRunsOnWithGitHubsArm64RunnerAsDefault(): void
    {
        self::assertSame("\${{ fromJSON(vars.CI_ARM_RUNS_ON || '\"ubuntu-24.04-arm\"') }}", $this->job()['runs-on'] ?? null);
    }

    public function testItBuildsTheImageAsTheImageJobDoesAndPrintsTheBuildSeconds(): void
    {
        $build = $this->stepRunning('docker build');

        self::assertSame('build', $build['id'] ?? null);
        self::assertStringContainsString("docker build --tag intakt:ci .\necho \"arm64 build took \$((SECONDS - start)) seconds\"", $build['run'] ?? '');
    }

    public function testItSmokeTestsTheImageItBuilt(): void
    {
        self::assertSame('docker/smoke-test.sh intakt:ci', $this->stepRunning('smoke-test.sh')['run'] ?? null);
    }

    public function testTheFirstStartRunsAfterAFailedSmokeTestButNotWithoutABuiltImageAndPrintsItsSeconds(): void
    {
        $firstStart = $this->stepRunning('verify-first-start.sh');

        self::assertSame("\${{ !cancelled() && steps.build.outcome == 'success' }}", $firstStart['if'] ?? null);
        self::assertStringContainsString("bash docker/verify-first-start.sh intakt:ci\necho \"arm64 first start took \$((SECONDS - start)) seconds\"", $firstStart['run'] ?? '');
    }

    /**
     * @return Step
     */
    private function stepRunning(string $command): array
    {
        $step = array_first(array_filter($this->job()['steps'] ?? [], static fn (array $step): bool => str_contains($step['run'] ?? '', $command)));
        self::assertIsArray($step);

        return $step;
    }

    /**
     * @return Job
     */
    private function job(): array
    {
        $jobs = $this->workflow()['jobs'] ?? [];
        self::assertIsArray($jobs);
        self::assertSame(['image'], array_keys($jobs));

        /** @var Job $job */
        $job = $jobs['image'];

        return $job;
    }

    /**
     * @return array<string, mixed>
     */
    private function workflow(): array
    {
        /** @var array<string, mixed> $workflow */
        $workflow = Yaml::parseFile(self::WORKFLOW);

        return $workflow;
    }
}
