<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * @phpstan-type Step array{id?: string, name?: string, if?: string, uses?: string, run?: string, env?: array<string, string>, continue-on-error?: bool|string}
 * @phpstan-type Job array{if?: string, steps?: list<Step>}
 * @phpstan-type WorkflowFile array{on?: string|array<mixed>, jobs?: array<string, Job>}
 */
#[CoversNothing]
final class AppImageWorkflowTest extends TestCase
{
    private const string WORKFLOWS = __DIR__.'/../../.github/workflows';
    private const string FORK_GUARD = "github.event.pull_request.head.repo.full_name == github.repository || github.event_name == 'push'";
    private const string BUILD = 'docker build --tag intakt:ci .';
    private const string DECIDE = 'decide';
    private const string NEEDS_IMAGE = "steps.decide.outputs.image == 'yes'";

    public function testAPullRequestOrABranchPushBuildsTheAppImageOnce(): void
    {
        self::assertSame(['ci.yml image'], $this->jobsRunning('docker build'));
    }

    /**
     * @param string|array<mixed> $on
     */
    #[DataProvider('triggers')]
    public function testEveryTriggerThatStartsOnABranchPushOrPullRequestIsScanned(string|array $on, bool $scanned): void
    {
        self::assertSame($scanned, $this->startsOnPullRequestsOrBranchPushes($on));
    }

    /**
     * @return iterable<string, array{string|array<mixed>, bool}>
     */
    public static function triggers(): iterable
    {
        yield 'a bare push' => ['push', true];
        yield 'a list with pull requests' => [['schedule', 'pull_request'], true];
        yield 'an unfiltered push' => [['push' => null], true];
        yield 'a push to main' => [['push' => ['branches' => ['main']]], true];
        yield 'a push ignoring a branch' => [['push' => ['branches-ignore' => ['release']]], true];
        yield 'a push on paths' => [['push' => ['paths' => ['src/**']]], true];
        yield 'a pull request target' => [['pull_request_target' => null], true];
        yield 'a push of tags only' => [['push' => ['tags' => ['v*']], 'workflow_dispatch' => null], false];
        yield 'a push ignoring tags only' => [['push' => ['tags-ignore' => ['v*']]], false];
        yield 'a schedule' => [['schedule' => [['cron' => '0 0 * * *']], 'workflow_dispatch' => null], false];
    }

    public function testBothImageChecksRunOnlyInTheImageJob(): void
    {
        self::assertSame(['ci.yml image'], $this->jobsRunning('docker/smoke-test.sh'));
        self::assertSame(['ci.yml image'], $this->jobsRunning('docker/verify-first-start.sh'));
        self::assertSame(['ci.yml image'], $this->jobsRunning('docker/verify-upgrade.sh'));
    }

    public function testTheImageJobChecksTheImageItBuiltWithEveryScript(): void
    {
        $checks = array_filter($this->imageJob()['steps'] ?? [], static fn (array $step): bool => self::DECIDE !== ($step['id'] ?? null));

        self::assertSame(
            ['.github/ci/docker-hub-login.sh', self::BUILD, 'docker/smoke-test.sh intakt:ci', 'bash docker/verify-first-start.sh intakt:ci', 'bash docker/verify-upgrade.sh intakt:ci "$BASE"'],
            array_column($checks, 'run'),
        );
    }

    public function testTheUpgradeCheckRunsOnlyWhenNeededAndNotWithoutABuiltImage(): void
    {
        $upgrade = $this->stepRunning('verify-upgrade.sh');

        self::assertSame("\${{ !cancelled() && steps.build.outcome == 'success' && steps.decide.outputs.upgrade == 'yes' }}", $upgrade['if'] ?? null);
        self::assertSame('${{ steps.decide.outputs.base }}', $upgrade['env']['BASE'] ?? null);
    }

    public function testEveryPushToMainNeedsTheUpgradeCheckAndAPullRequestAsksTheFilterAboutItsMergeBase(): void
    {
        $run = $this->decision()['run'] ?? '';

        self::assertStringContainsString("base=\$(git rev-parse HEAD~1)\n  image=yes\n  upgrade=yes\n", $run);
        self::assertStringContainsString('base=$(git merge-base "origin/$BASE_REF" "$HEAD_SHA")', $run);
        self::assertStringContainsString('upgrade=$(.github/ci/upgrade-needed.sh "$base" "$HEAD_SHA")', $run);
    }

    public function testEveryPushToMainNeedsTheAppImageAndAPullRequestAsksTheImageFilterAboutItsMergeBase(): void
    {
        $run = $this->decision()['run'] ?? '';

        self::assertStringContainsString("image=yes\n  upgrade=yes\n", $run);
        self::assertStringContainsString('image=$(.github/ci/image-needed.sh "$base" "$HEAD_SHA")', $run);
        self::assertStringContainsString('echo "image=$image" >> "$GITHUB_OUTPUT"', $run);
    }

    public function testAFailingFilterFailsTheDecisionInsteadOfSkippingTheImage(): void
    {
        $decision = $this->decision();

        self::assertStringStartsWith("set -euo pipefail\n", $decision['run'] ?? '');
        self::assertArrayNotHasKey('continue-on-error', $decision);
    }

    public function testTheLoginTheBuildAndTheSmokeTestRunOnlyWhenTheChangeNeedsTheAppImage(): void
    {
        foreach (['docker-hub-login.sh', self::BUILD, 'smoke-test.sh'] as $script) {
            self::assertSame(self::NEEDS_IMAGE, $this->stepRunning($script)['if'] ?? null, $script);
        }
    }

    public function testTheChangeIsDecidedBeforeTheImageJobTouchesDocker(): void
    {
        $steps = $this->imageJob()['steps'] ?? [];
        $decide = array_find_key($steps, static fn (array $step): bool => self::DECIDE === ($step['id'] ?? null));
        $firstRun = array_find_key($steps, static fn (array $step): bool => isset($step['run']) && self::DECIDE !== ($step['id'] ?? null));

        self::assertIsInt($decide);
        self::assertIsInt($firstRun);
        self::assertLessThan($firstRun, $decide);
    }

    public function testTheImageJobReportsOnEveryPullRequestBecauseNoPathsFilterStartsIt(): void
    {
        $on = $this->parse(self::WORKFLOWS.'/ci.yml')['on'] ?? [];

        self::assertIsArray($on);
        self::assertArrayHasKey('pull_request', $on);
        self::assertNull($on['pull_request']);
    }

    public function testTheFirstStartCheckRunsAfterAFailedSmokeTestButNotWithoutABuiltImage(): void
    {
        $steps = $this->imageJob()['steps'] ?? [];
        $build = array_first(array_filter($steps, static fn (array $step): bool => ($step['run'] ?? '') === self::BUILD));
        $firstStart = $this->stepRunning('verify-first-start.sh');

        self::assertSame('build', $build['id'] ?? null);
        self::assertSame("\${{ !cancelled() && steps.build.outcome == 'success' }}", $firstStart['if'] ?? null);
    }

    public function testTheImageJobIsForkGuarded(): void
    {
        self::assertSame(self::FORK_GUARD, $this->imageJob()['if'] ?? null);
    }

    /**
     * @return Step
     */
    private function decision(): array
    {
        $step = array_first(array_filter($this->imageJob()['steps'] ?? [], static fn (array $step): bool => self::DECIDE === ($step['id'] ?? null)));
        self::assertIsArray($step);

        return $step;
    }

    /**
     * @return Step
     */
    private function stepRunning(string $script): array
    {
        $step = array_first(array_filter($this->imageJob()['steps'] ?? [], static fn (array $step): bool => str_contains($step['run'] ?? '', $script)));
        self::assertIsArray($step);

        return $step;
    }

    /**
     * @return list<string>
     */
    private function jobsRunning(string $command): array
    {
        $found = [];
        foreach ($this->runsByJob() as $job => $runs) {
            $matching = array_filter($runs, static fn (string $run): bool => str_contains($run, $command));
            $found = [...$found, ...array_fill(0, count($matching), $job)];
        }

        return $found;
    }

    /**
     * @return array<string, list<string>>
     */
    private function runsByJob(): array
    {
        $runs = [];
        foreach ($this->workflowsOnPullRequestsOrBranchPushes() as $file => $workflow) {
            foreach ($workflow['jobs'] ?? [] as $name => $job) {
                $runs[$file.' '.$name] = array_column($job['steps'] ?? [], 'run');
            }
        }

        return $runs;
    }

    /**
     * @return array<string, WorkflowFile>
     */
    private function workflowsOnPullRequestsOrBranchPushes(): array
    {
        $workflows = [];
        foreach (new Finder()->files()->in(self::WORKFLOWS)->name(['*.yml', '*.yaml'])->sortByName() as $file) {
            $workflow = $this->parse($file->getPathname());
            if ($this->runsOnPullRequestsOrBranchPushes($workflow)) {
                $workflows[$file->getFilename()] = $workflow;
            }
        }

        return $workflows;
    }

    /**
     * @param WorkflowFile $workflow
     */
    private function runsOnPullRequestsOrBranchPushes(array $workflow): bool
    {
        return $this->startsOnPullRequestsOrBranchPushes($workflow['on'] ?? []);
    }

    /**
     * @param string|array<mixed> $on
     */
    private function startsOnPullRequestsOrBranchPushes(string|array $on): bool
    {
        $triggers = is_string($on) ? [$on => null] : (array_is_list($on) ? array_flip(array_filter($on, is_string(...))) : $on);
        $push = is_array($triggers['push'] ?? null) ? $triggers['push'] : [];

        // Tag-only pushes are left out because GitHub starts them on no branch, and a release builds its own image in .github/ci/release-image.sh.
        $tagsOnly = !isset($push['branches']) && !isset($push['branches-ignore']) && (isset($push['tags']) || isset($push['tags-ignore']));

        return array_key_exists('pull_request', $triggers) || array_key_exists('pull_request_target', $triggers) || (array_key_exists('push', $triggers) && !$tagsOnly);
    }

    /**
     * @return Job
     */
    private function imageJob(): array
    {
        $jobs = $this->parse(self::WORKFLOWS.'/ci.yml')['jobs'] ?? [];
        self::assertArrayHasKey('image', $jobs);

        return $jobs['image'];
    }

    /**
     * @return WorkflowFile
     */
    private function parse(string $path): array
    {
        /** @var WorkflowFile $workflow */
        $workflow = Yaml::parseFile($path);

        return $workflow;
    }
}
