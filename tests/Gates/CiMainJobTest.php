<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * @phpstan-type Step array{name?: string, id?: string, if?: string, run?: string, env?: array<string, string|int>, uses?: string, with?: array<string, string|int>}
 * @phpstan-type Job array{if?: string, needs?: string, 'timeout-minutes'?: int, permissions?: array<string, string>, steps?: list<Step>}
 * @phpstan-type WorkflowFile array{permissions: array<string, string>, jobs: array<string, Job>}
 */
#[CoversNothing]
final class CiMainJobTest extends SandboxTestCase
{
    private const string WORKFLOW = __DIR__.'/../../.github/workflows/ci.yml';

    private const string INTO_MAIN = "github.event_name == 'pull_request' && github.base_ref == 'main'";

    private const string TREE = '0123456789012345678901234567890123456789';

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

        self::assertStringContainsString('tree=$(timeout -k 2 "$LOOKUP_TIMEOUT_SECONDS" git rev-parse "$GITHUB_SHA^{tree}") && passed=$(timeout -k 2 "$LOOKUP_TIMEOUT_SECONDS" .github/ci/pr-run-passed.sh "$GITHUB_SHA" "$tree")', $step['run'] ?? '');
    }

    public function testAFailedLookupWarnsAndRunsEveryJob(): void
    {
        [$exit, $printed, $written] = $this->decide("echo 'gh: temporary API outage' >&2; exit 1");

        self::assertSame([0, "full=true\n"], [$exit, $written]);
        self::assertStringContainsString('::warning::The lookup of a passed pull request run', $printed);
    }

    public function testALookupThatStallsIsStoppedAndRunsEveryJob(): void
    {
        [$exit, $printed, $written] = $this->decide('exec sleep 30', '1');

        self::assertSame([0, "full=true\n"], [$exit, $written]);
        self::assertStringContainsString('::warning::The lookup of a passed pull request run', $printed);
    }

    public function testAStalledGitIsStoppedAndRunsEveryJobToo(): void
    {
        [$exit, $printed, $written] = $this->decide('echo 1', '1', 'exec sleep 30');

        self::assertSame([0, "full=true\n"], [$exit, $written]);
        self::assertStringContainsString('::warning::The lookup of a passed pull request run', $printed);
    }

    public function testAStalledGitThatIgnoresTheStopSignalIsKilledAndRunsEveryJob(): void
    {
        [$exit, $printed, $written] = $this->decide('echo 1', '1', "trap '' TERM; exec sleep 30");

        self::assertSame([0, "full=true\n"], [$exit, $written]);
        self::assertStringContainsString('::warning::The lookup of a passed pull request run', $printed);
    }

    public function testAnApiLookupThatIgnoresTheStopSignalIsKilledAndRunsEveryJob(): void
    {
        [$exit, $printed, $written] = $this->decide("trap '' TERM; exec sleep 30", '1');

        self::assertSame([0, "full=true\n"], [$exit, $written]);
        self::assertStringContainsString('::warning::The lookup of a passed pull request run', $printed);
    }

    public function testTheLookupLimitIsSetOnTheStepAndEndsWellBeforeTheJob(): void
    {
        self::assertSame(300, $this->step('main', 'passed')['env']['LOOKUP_TIMEOUT_SECONDS'] ?? null);
        self::assertSame(20, $this->job('main')['timeout-minutes'] ?? null);
    }

    public function testAMatchingRunSkipsTheFullRun(): void
    {
        [$exit, $printed, $written] = $this->decide('case "$*" in */artifacts*) echo main-tested-tree-'.self::TREE.';; *) echo 1;; esac');

        self::assertSame([0, "full=false\n"], [$exit, $written]);
        self::assertStringNotContainsString('::warning::', $printed);
    }

    public function testASkippedPushFillsAndSavesOnlyTheCacheThatIsMissing(): void
    {
        $skipped = "steps.passed.outputs.full == 'false'";
        $noComposer = "steps.composer-cache.outputs.cache-hit != 'true'";
        $eitherMisses = $skipped." && (steps.image-cache.outputs.cache-hit != 'true' || ".$noComposer.')';
        $conditions = [];
        foreach ($this->job('main')['steps'] ?? [] as $step) {
            if (isset($step['name'], $step['if'])) {
                $conditions[$step['name']] = $step['if'];
            }
        }

        self::assertSame([
            'Name the CI image cache' => $skipped,
            'Look up the CI image cache' => $skipped,
            "Restore Composer's cache" => $skipped,
            'Log in to Docker Hub' => $eitherMisses,
            'Build or restore the CI image' => $eitherMisses,
            "Fill Composer's cache" => $skipped.' && '.$noComposer,
            "Save Composer's cache" => $skipped.' && '.$noComposer,
        ], $conditions);
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
            self::assertSame("\${{ !cancelled() && needs.main.outputs.full != 'false' && ".self::GUARD.' }}', $job['if'] ?? null, $name);
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
     * @return array{int, string, string}
     */
    private function decide(string $gh, string $limit = '300', string $git = 'echo '.self::TREE): array
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('bin/gh', "#!/bin/sh\n".$gh."\n");
        $sandbox->write('bin/git', "#!/bin/sh\n".$git."\n");
        $sandbox->write('output', '');
        chmod($sandbox->root.'/bin/gh', 0o755);
        chmod($sandbox->root.'/bin/git', 0o755);
        $process = new Process(['bash', '-e', '-c', $this->step('main', 'passed')['run'] ?? 'exit 9'], dirname(__DIR__, 2), [
            'PATH' => $sandbox->root.'/bin:'.getenv('PATH'),
            'GITHUB_SHA' => 'abcdef',
            'GITHUB_REPOSITORY' => 'example/intakt',
            'GITHUB_OUTPUT' => $sandbox->root.'/output',
            'LOOKUP_TIMEOUT_SECONDS' => $limit,
        ], timeout: 10);
        $exit = $process->run();

        return [$exit, $process->getOutput(), (string) file_get_contents($sandbox->root.'/output')];
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
