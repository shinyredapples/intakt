<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
final class CiImagePublishTest extends SandboxTestCase
{
    private const string SCRIPT = __DIR__.'/../../.github/ci/publish-ci-image.sh';

    private const string WORKFLOW = __DIR__.'/../../.github/workflows/ci-image.yml';

    private const string IMAGE = 'ghcr.io/example/repo-ci:0123abcd';

    // Logs every call, so a test compares the exact commands; inspect answers from the PUBLISHED file or with INSPECT_ERROR, as the registry would.
    private const string FAKE_DOCKER = <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        if [[ "$1" == login ]]; then echo "$* password=$(cat)" >> "$CALLS"; exit 0; fi
        echo "$*" >> "$CALLS"
        if [[ "$1 ${2:-} ${3:-}" == 'buildx imagetools inspect' ]]; then grep -qx -- "$4" "$PUBLISHED" || { echo "${INSPECT_ERROR:-ERROR: $4: not found}" >&2; exit 1; }; fi
        BASH;

    // Stands in for build-image.sh: the hash for --print-hash, otherwise one logged build, as the real script builds the image once.
    private const string FAKE_BUILD = <<<'BASH'
        #!/usr/bin/env bash
        if [[ "${1:-}" == --print-hash ]]; then echo 0123abcd; exit 0; fi
        echo "build-image GITHUB_ENV=$GITHUB_ENV" >> "$CALLS"
        BASH;

    public function testAnImageThatIsNotPublishedYetIsBuiltAndPushedUnderItsHash(): void
    {
        $sandbox = $this->sandbox(published: false);

        $result = $this->runScript($sandbox);

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame([
            'login ghcr.io --username octocat --password-stdin password=token',
            'buildx imagetools inspect '.self::IMAGE,
            'build-image GITHUB_ENV=/dev/null',
            'tag intakt-ci:0123abcd '.self::IMAGE,
            'push '.self::IMAGE,
        ], $this->calls($sandbox));
    }

    public function testAnImagePublishedForTheHashIsLeftAsItIs(): void
    {
        $sandbox = $this->sandbox(published: true);

        $result = $this->runScript($sandbox);

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertStringContainsString('is published already', $result->output);
        self::assertSame([], array_values(array_filter($this->calls($sandbox), static fn (string $call): bool => !str_starts_with($call, 'login ') && !str_starts_with($call, 'buildx '))));
    }

    #[DataProvider('registryErrors')]
    public function testARegistryThatCannotBeReadFailsTheRunWithoutBuilding(string $error): void
    {
        $sandbox = $this->sandbox(published: false);

        $result = $this->runScript($sandbox, $error);

        self::assertFalse($result->succeeded());
        self::assertStringContainsString($error, $result->errors);
        self::assertSame([], array_values(array_filter($this->calls($sandbox), static fn (string $call): bool => str_starts_with($call, 'build-image') || str_starts_with($call, 'push '))));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function registryErrors(): iterable
    {
        yield 'outage' => ['ERROR: unexpected status from HEAD request: 503 Service Unavailable'];
        yield 'token endpoint not found' => ['ERROR: failed to authorize: failed to fetch anonymous token: unexpected status: 404 Not Found'];
        yield 'another reference not found' => ['ERROR: ghcr.io/example/other-ci:0123abcd: not found'];
    }

    public function testOnlyMainPublishesWithPackagesWriteOnTheOneJob(): void
    {
        /** @var array{on: array{push: array{branches: list<string>, paths: list<string>}, workflow_dispatch: mixed}, permissions: mixed, jobs: array{publish: array{if: string, permissions: mixed, steps: list<array{run?: string}>}}} $workflow */
        $workflow = Yaml::parseFile(self::WORKFLOW);

        self::assertSame(['push', 'workflow_dispatch'], array_keys($workflow['on']));
        self::assertSame(['main'], $workflow['on']['push']['branches']);
        self::assertContains('.github/ci/Dockerfile', $workflow['on']['push']['paths']);
        self::assertSame("github.ref == 'refs/heads/main'", $workflow['jobs']['publish']['if']);
        self::assertSame(['contents' => 'read'], $workflow['permissions']);
        self::assertSame(['contents' => 'read', 'packages' => 'write'], $workflow['jobs']['publish']['permissions']);
        self::assertSame('.github/ci/publish-ci-image.sh', $workflow['jobs']['publish']['steps'][2]['run'] ?? '');
    }

    private function sandbox(bool $published): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('calls', '');
        $sandbox->write('published', $published ? self::IMAGE."\n" : "\n");
        $sandbox->write('bin/docker', self::FAKE_DOCKER);
        $sandbox->write('.github/ci/build-image.sh', self::FAKE_BUILD);

        return $sandbox;
    }

    private function runScript(Sandbox $sandbox, string $inspectError = ''): ProcessResult
    {
        $environment = ['INSPECT_ERROR' => $inspectError, 'REPOSITORY' => 'Example/Repo', 'ACTOR' => 'octocat', 'GHCR_TOKEN' => 'token', 'CALLS' => $sandbox->root.'/calls', 'PUBLISHED' => $sandbox->root.'/published'];

        return $sandbox->runScript('chmod +x bin/docker .github/ci/build-image.sh; PATH="$PWD/bin:$PATH"; bash '.escapeshellarg(self::SCRIPT), $environment);
    }

    /**
     * @return list<string>
     */
    private function calls(Sandbox $sandbox): array
    {
        $calls = file_get_contents($sandbox->root.'/calls');

        return false === $calls || '' === $calls ? [] : explode("\n", rtrim($calls, "\n"));
    }
}
