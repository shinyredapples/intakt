<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
final class ReleaseImageTest extends SandboxTestCase
{
    private const string SCRIPT = __DIR__.'/../../.github/ci/release-image.sh';

    private const string WORKFLOW = __DIR__.'/../../.github/workflows/release-image.yml';

    // Logs every call, so a test compares the exact commands; login reads the token from stdin, because the script must never pass it as an argument.
    private const string FAKE_DOCKER = <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        if [[ "$1" == login ]]; then echo "$* password=$(cat)" >> "$CALLS"; exit 0; fi
        echo "$*" >> "$CALLS"
        BASH;

    public function testTheHighestReleaseTagIsBuiltFromItsCheckoutAndPushedAsItsVersionAndLatest(): void
    {
        $sandbox = $this->releaseSandbox(['v0.1.2', 'v0.9.0', 'v0.10.0']);
        $revision = $this->checkOut($sandbox, 'v0.10.0');

        $result = $this->runScript($sandbox, 'v0.10.0');

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame([
            'build --label org.opencontainers.image.source=https://github.com/Example/Repo --label org.opencontainers.image.version=0.10.0 --label org.opencontainers.image.revision='.$revision.' --tag intakt:release release',
            'smoke intakt:release',
            'login ghcr.io --username octocat --password-stdin password=token',
            'tag intakt:release ghcr.io/example/repo:0.10.0',
            'push ghcr.io/example/repo:0.10.0',
            'tag intakt:release ghcr.io/example/repo:latest',
            'push ghcr.io/example/repo:latest',
        ], $this->calls($sandbox));
    }

    public function testAnOlderTagIsPushedOnlyAsItsVersionSoLatestNeverMovesBack(): void
    {
        $sandbox = $this->releaseSandbox(['v0.1.2', 'v0.9.0', 'v0.10.0']);
        $this->checkOut($sandbox, 'v0.9.0');

        self::assertTrue($this->runScript($sandbox, 'v0.9.0')->succeeded());
        self::assertSame(['push ghcr.io/example/repo:0.9.0'], array_values(array_filter($this->calls($sandbox), static fn (string $call): bool => str_starts_with($call, 'push '))));
    }

    public function testATagWhoseTreeHasNoReleaseScriptIsStillPublished(): void
    {
        $sandbox = $this->releaseSandbox(['v0.1.0']);
        $this->checkOut($sandbox, 'v0.1.0');

        $result = $this->runScript($sandbox, 'v0.1.0');

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertFileDoesNotExist($sandbox->root.'/release/.github/ci/release-image.sh');
        self::assertContains('push ghcr.io/example/repo:0.1.0', $this->calls($sandbox));
    }

    public function testACheckoutThatIsNotTheTagsCommitBuildsNothing(): void
    {
        $sandbox = $this->releaseSandbox(['v0.1.0', 'v0.2.0']);
        $this->checkOut($sandbox, 'v0.1.0');

        $result = $this->runScript($sandbox, 'v0.2.0');

        self::assertFalse($result->succeeded());
        self::assertStringContainsString('is not the commit of v0.2.0', $result->errors);
        self::assertSame([], $this->calls($sandbox));
    }

    public function testAFailingSmokeTestPushesNothing(): void
    {
        $sandbox = $this->releaseSandbox(['v0.1.2']);
        $this->checkOut($sandbox, 'v0.1.2');
        $sandbox->write('docker/smoke-test.sh', "#!/usr/bin/env bash\nexit 1\n");

        self::assertFalse($this->runScript($sandbox, 'v0.1.2')->succeeded());
        self::assertSame(['build'], array_map(static fn (string $call): string => explode(' ', $call)[0], $this->calls($sandbox)));
    }

    #[DataProvider('tagsThatAreNoRelease')]
    public function testATagThatIsNoReleaseBuildsNothing(string $tag): void
    {
        $sandbox = $this->releaseSandbox(['v0.1.2']);
        $this->checkOut($sandbox, 'v0.1.2');

        $result = $this->runScript($sandbox, $tag);

        self::assertFalse($result->succeeded());
        self::assertStringContainsString('is not a release tag', $result->errors);
        self::assertSame([], $this->calls($sandbox));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tagsThatAreNoRelease(): iterable
    {
        yield 'no v' => ['0.1.2'];
        yield 'pre-release suffix' => ['v0.1.2-rc.1'];
        yield 'leading zero' => ['v0.01.2'];
        yield 'two numbers' => ['v0.1'];
        yield 'empty' => [''];
    }

    public function testTheWorkflowRunsOnlyForTagsAndAnOwnerDispatchWithPackagesWriteOnItsJob(): void
    {
        $workflow = $this->workflow();

        self::assertSame(['push', 'workflow_dispatch'], array_keys($workflow['on']));
        self::assertSame(['tags' => ['v*']], $workflow['on']['push']);
        self::assertSame(['tag' => ['description' => 'An existing release tag, vX.Y.Z', 'required' => true, 'type' => 'string']], $workflow['on']['workflow_dispatch']['inputs']);
        self::assertSame(['contents' => 'read'], $workflow['permissions']);
        self::assertSame(['contents' => 'read', 'packages' => 'write'], $workflow['jobs']['image']['permissions']);
    }

    public function testTheTagIsCheckedOutApartAndTheScriptRunsFromTheWorkflowsRevision(): void
    {
        $steps = $this->workflow()['jobs']['image']['steps'];

        self::assertSame(['persist-credentials' => false], $steps[0]['with']);
        self::assertSame('refs/tags/${{ inputs.tag || github.ref_name }}', $steps[1]['with']['ref']);
        self::assertSame('release', $steps[1]['with']['path']);
        self::assertFalse($steps[1]['with']['persist-credentials']);
        self::assertSame('release', $steps[3]['env']['CONTEXT']);
        self::assertSame('${{ inputs.tag || github.ref_name }}', $steps[3]['env']['TAG']);
        self::assertSame('.github/ci/release-image.sh', $steps[3]['run']);
    }

    /**
     * @param list<string> $tags
     */
    private function releaseSandbox(array $tags): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('calls', '');
        $sandbox->write('bin/docker', self::FAKE_DOCKER);
        $sandbox->write('docker/smoke-test.sh', "#!/usr/bin/env bash\necho \"smoke \$*\" >> \"\$CALLS\"\n");
        $sandbox->write('release/README.md', "release\n");
        $git = static fn (string ...$arguments): ProcessResult => $sandbox->run('git', '-C', 'release', ...$arguments);
        $git('init', '--quiet', '--initial-branch=main');

        foreach ($tags as $tag) {
            $git('-c', 'user.name=t', '-c', 'user.email=t@example.com', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '--allow-empty', '--message', $tag);
            $git('-c', 'tag.gpgsign=false', 'tag', $tag);
        }

        return $sandbox;
    }

    private function checkOut(Sandbox $sandbox, string $tag): string
    {
        $sandbox->run('git', '-C', 'release', 'checkout', '--quiet', '--detach', $tag);

        return trim($sandbox->run('git', '-C', 'release', 'rev-parse', 'HEAD')->output);
    }

    private function runScript(Sandbox $sandbox, string $tag): ProcessResult
    {
        $environment = ['TAG' => $tag, 'CONTEXT' => 'release', 'REPOSITORY' => 'Example/Repo', 'ACTOR' => 'octocat', 'GHCR_TOKEN' => 'token', 'CALLS' => $sandbox->root.'/calls'];

        return $sandbox->runScript('chmod +x bin/docker docker/smoke-test.sh; PATH="$PWD/bin:$PATH"; bash '.escapeshellarg(self::SCRIPT), $environment);
    }

    /**
     * @return array{on: array{push: mixed, workflow_dispatch: array{inputs: mixed}}, permissions: mixed, jobs: array{image: array{permissions: mixed, steps: list<array{with: array<string, mixed>, env: array<string, string>, run: string}>}}}
     */
    private function workflow(): array
    {
        /** @var array{on: array{push: mixed, workflow_dispatch: array{inputs: mixed}}, permissions: mixed, jobs: array{image: array{permissions: mixed, steps: list<array{with: array<string, mixed>, env: array<string, string>, run: string}>}}} $workflow */
        $workflow = Yaml::parseFile(self::WORKFLOW);

        return $workflow;
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
