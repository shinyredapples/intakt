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

    // Logs every call, so a test compares the exact commands; login reads the token from stdin, because the script must never pass it as an argument.
    private const string FAKE_DOCKER = <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        if [[ "$1" == login ]]; then echo "$* password=$(cat)" >> "$CALLS"; exit 0; fi
        echo "$*" >> "$CALLS"
        BASH;

    public function testAReleaseTagIsBuiltSmokeTestedAndPushedAsItsVersionAndLatest(): void
    {
        $sandbox = $this->releaseSandbox();
        $revision = trim($sandbox->git('rev-parse', 'HEAD')->output);

        $result = $this->runScript($sandbox, 'v0.1.2');

        self::assertTrue($result->succeeded(), $result->errors);
        self::assertSame([
            'build --label org.opencontainers.image.source=https://github.com/Example/Repo --label org.opencontainers.image.version=0.1.2 --label org.opencontainers.image.revision='.$revision.' --tag intakt:release .',
            'smoke intakt:release',
            'login ghcr.io --username octocat --password-stdin password=token',
            'tag intakt:release ghcr.io/example/repo:0.1.2',
            'push ghcr.io/example/repo:0.1.2',
            'tag intakt:release ghcr.io/example/repo:latest',
            'push ghcr.io/example/repo:latest',
        ], $this->calls($sandbox));
    }

    public function testAFailingSmokeTestPushesNothing(): void
    {
        $sandbox = $this->releaseSandbox();
        $sandbox->write('docker/smoke-test.sh', "#!/usr/bin/env bash\nexit 1\n");

        self::assertFalse($this->runScript($sandbox, 'v0.1.2')->succeeded());
        self::assertSame(['build'], array_map(static fn (string $call): string => explode(' ', $call)[0], $this->calls($sandbox)));
    }

    #[DataProvider('tagsThatAreNoRelease')]
    public function testATagThatIsNoReleaseBuildsNothing(string $tag): void
    {
        $sandbox = $this->releaseSandbox();

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
        /** @var array{on: array<string, mixed>, permissions: array<string, string>, jobs: array{image: array{permissions: array<string, string>}}} $workflow */
        $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/release-image.yml');

        self::assertSame(['push', 'workflow_dispatch'], array_keys($workflow['on']));
        self::assertSame(['tags' => ['v*']], $workflow['on']['push']);
        self::assertSame(['contents' => 'read'], $workflow['permissions']);
        self::assertSame(['contents' => 'read', 'packages' => 'write'], $workflow['jobs']['image']['permissions']);
    }

    private function releaseSandbox(): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('calls', '');
        $sandbox->write('bin/docker', self::FAKE_DOCKER);
        $sandbox->write('docker/smoke-test.sh', "#!/usr/bin/env bash\necho \"smoke \$*\" >> \"\$CALLS\"\n");
        $sandbox->startRepository();
        $sandbox->commitAll('chore: start');

        return $sandbox;
    }

    private function runScript(Sandbox $sandbox, string $tag): ProcessResult
    {
        $environment = ['TAG' => $tag, 'REPOSITORY' => 'Example/Repo', 'ACTOR' => 'octocat', 'GHCR_TOKEN' => 'token', 'CALLS' => $sandbox->root.'/calls'];

        return $sandbox->runScript('chmod +x bin/docker docker/smoke-test.sh; PATH="$PWD/bin:$PATH"; bash '.escapeshellarg(self::SCRIPT), $environment);
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
