<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Yaml\Yaml;

// No locked package carries a checksum, so an archive a pull request plants in Composer's cache would be installed unchecked by a later main run.
#[CoversNothing]
final class ComposerCacheWorkflowTest extends SandboxTestCase
{
    private const string WORKFLOW = __DIR__.'/../../.github/workflows/ci.yml';

    private const string PINNED_CACHE_ACTION = '#^\s+uses: actions/cache/(restore|save)@[0-9a-f]{40} \# v\S+$#';

    // Logs every argument on a line of its own, so a test reads the exact mount and environment the gates get.
    private const string FAKE_DOCKER = <<<'BASH'
        #!/usr/bin/env bash
        printf '%s\n' "$@" > "$CALLS"
        BASH;

    public function testTheCheckJobRestoresComposersCacheKeyedByTheRunnerOsAndTheLock(): void
    {
        $restore = $this->step("Restore Composer's cache");

        self::assertSame('actions/cache/restore', strstr($restore['uses'] ?? '', '@', true));
        self::assertSame([
            'path' => '${{ runner.temp }}/composer-cache',
            'key' => "composer-\${{ runner.os }}-\${{ hashFiles('composer.lock') }}",
            'restore-keys' => 'composer-${{ runner.os }}-',
        ], $restore['with'] ?? null);
    }

    public function testThePersistenceJobRestoresTheCheckJobsCacheAndNeverSavesIt(): void
    {
        $restore = $this->step("Restore Composer's cache", 'persistence');

        self::assertSame('actions/cache/restore', strstr($restore['uses'] ?? '', '@', true));
        self::assertSame($this->step("Restore Composer's cache")['with'] ?? null, $restore['with'] ?? null);
        self::assertSame([], array_values(array_filter($this->steps('persistence'), static fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'actions/cache@') || str_starts_with($step['uses'] ?? '', 'actions/cache/save@'))), 'The persistence job must restore only; the check job of a push to main saves.');
        self::assertSame('${{ runner.temp }}/composer-cache', $this->step('Run the persistence group on each database image', 'persistence')['env']['COMPOSER_CACHE'] ?? null);
    }

    public function testOnlyAPushToMainSavesComposersCache(): void
    {
        $save = $this->step("Save Composer's cache");

        self::assertSame('actions/cache/save', strstr($save['uses'] ?? '', '@', true));
        self::assertSame("github.event_name == 'push' && github.ref == 'refs/heads/main' && steps.composer-cache.outputs.cache-hit != 'true'", $save['if'] ?? null);
        self::assertSame('${{ steps.composer-cache.outputs.cache-primary-key }}', $save['with']['key'] ?? null);
        self::assertSame([], array_values(array_filter($this->steps(), static fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'actions/cache@'))), 'The check job must not use actions/cache, which saves on every event.');
    }

    public function testTheCacheActionsArePinnedByCommit(): void
    {
        $lines = array_filter(explode("\n", (string) file_get_contents(self::WORKFLOW)), static fn (string $line): bool => str_contains($line, 'actions/cache'));

        self::assertCount(8, $lines);
        foreach ($lines as $line) {
            self::assertMatchesRegularExpression(self::PINNED_CACHE_ACTION, $line);
        }
    }

    public function testAPullRequestGetsComposersCacheReadOnly(): void
    {
        $sandbox = $this->emptySandbox();

        $result = $this->runGates($sandbox, 'pull_request', 'refs/pull/1/merge');

        self::assertTrue($result->succeeded(), $result->errors);
        $arguments = $this->dockerArguments($sandbox);
        self::assertContains($sandbox->root.'/temp/composer-cache:/composer-cache:ro', $arguments);
        self::assertContains('COMPOSER_CACHE_READ_ONLY=1', $arguments);
        self::assertContains('COMPOSER_CACHE_DIR=/composer-cache', $arguments);
        self::assertSame(0o755, $this->mode($sandbox));
    }

    public function testAPushToMainGetsComposersCacheWritableByTheContainersUser(): void
    {
        $sandbox = $this->emptySandbox();

        $result = $this->runGates($sandbox, 'push', 'refs/heads/main');

        self::assertTrue($result->succeeded(), $result->errors);
        $arguments = $this->dockerArguments($sandbox);
        self::assertContains($sandbox->root.'/temp/composer-cache:/composer-cache', $arguments);
        self::assertContains('COMPOSER_CACHE_READ_ONLY=0', $arguments);
        self::assertSame(0o777, $this->mode($sandbox));
    }

    private function runGates(Sandbox $sandbox, string $event, string $ref): ProcessResult
    {
        $sandbox->write('bin/docker', self::FAKE_DOCKER);
        $sandbox->write('run-the-gates.sh', $this->step('Run the gates')['run'] ?? '');
        $environment = ['EVENT' => $event, 'GITHUB_REF' => $ref, 'BASE_REF' => 'main', 'MERGE_SHA' => 'merge', 'HEAD_SHA' => 'head', 'DISPOSABLE_RUNNER' => 'true', 'RUNNER_ENVIRONMENT' => 'self-hosted', 'RUNNER_TEMP' => $sandbox->root.'/temp', 'GITHUB_WORKSPACE' => $sandbox->root, 'CI_IMAGE' => 'intakt-ci:0123abcd', 'CALLS' => $sandbox->root.'/calls'];

        return $sandbox->runScript('chmod +x bin/docker; PATH="$PWD/bin:$PATH"; umask 077; bash run-the-gates.sh', $environment);
    }

    /**
     * @return list<string>
     */
    private function dockerArguments(Sandbox $sandbox): array
    {
        return explode("\n", trim((string) file_get_contents($sandbox->root.'/calls')));
    }

    private function mode(Sandbox $sandbox): int
    {
        clearstatcache();

        return fileperms($sandbox->root.'/temp/composer-cache') & 0o777;
    }

    /**
     * @return array{uses?: string, if?: string, run?: string, with?: array<string, string>, env?: array<string, string>}
     */
    private function step(string $name, string $job = 'check'): array
    {
        $step = array_find($this->steps($job), static fn (array $step): bool => $name === ($step['name'] ?? null));
        self::assertIsArray($step, 'The '.$job.' job of ci.yml has no step named '.$name.'.');

        return $step;
    }

    /**
     * @return list<array{name?: string, uses?: string, if?: string, run?: string, with?: array<string, string>, env?: array<string, string>}>
     */
    private function steps(string $job = 'check'): array
    {
        /** @var array{jobs: array<string, array{steps: list<array{name?: string, uses?: string, if?: string, run?: string, with?: array<string, string>, env?: array<string, string>}>}>} $workflow */
        $workflow = Yaml::parseFile(self::WORKFLOW);

        return $workflow['jobs'][$job]['steps'];
    }
}
