<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// A stand-in docker on the PATH, because the sandbox and the unit tests have no Docker; the real checks run in CI (#1052).
#[CoversNothing]
final class SmokeTestScriptTest extends TestCase
{
    private const string FAKE_DOCKER = <<<'BASH'
        #!/bin/bash
        echo "$*" >> "$(dirname "$0")/calls"
        case "$1 $2" in
            "inspect --format") echo false ;;
            "run -d"*) echo container-id ;;
            "port "*) echo 127.0.0.1:1 ;;
            "buildx imagetools") echo "Digest: sha256:base" ;;
            "image inspect") echo sha256:image ;;
        esac
        BASH;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-smoke-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->directory.'/docker', self::FAKE_DOCKER);
        chmod($this->directory.'/docker', 0o755);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testWithoutAnImageTheScriptPrintsItsUsageAndExitsWithTwo(): void
    {
        $process = $this->smokeTest();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('usage:', $process->getErrorOutput());
    }

    public function testAContainerThatStopsFailsTheStepItStoppedIn(): void
    {
        $process = $this->smokeTest('intakt:test');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString("smoke test failed at step 'migrations'", $process->getErrorOutput());
    }

    public function testAFailedCheckStillRemovesTheContainerAndTheVolume(): void
    {
        $this->smokeTest('intakt:test');
        $calls = $this->dockerCalls();

        self::assertMatchesRegularExpression('/^rm -f intakt-smoke-\S+-web$/m', $calls);
        self::assertMatchesRegularExpression('/^volume rm -f intakt-smoke-\S+-data$/m', $calls);
    }

    public function testTheDigestsArePrintedOnTheirOwnLines(): void
    {
        $output = $this->smokeTest('intakt:test')->getOutput();

        self::assertStringContainsString("Image digest: sha256:image\n", $output);
        self::assertStringContainsString("Base image digest: sha256:base (dunglas/frankenphp:1.13.4-php8.5.12-trixie)\n", $output);
    }

    public function testTheSecretsReachTheContainerByNameOnly(): void
    {
        $this->smokeTest('intakt:test');

        self::assertMatchesRegularExpression('/^run -d .*-e APP_SECRET -e MERCURE_JWT_SECRET /m', $this->dockerCalls());
        self::assertDoesNotMatchRegularExpression('/SECRET=/', $this->dockerCalls());
    }

    private function smokeTest(string ...$arguments): Process
    {
        $process = new Process(
            ['bash', 'docker/smoke-test.sh', ...$arguments],
            dirname(__DIR__, 4),
            ['PATH' => $this->directory.':'.getenv('PATH')],
        );
        $process->run();

        return $process;
    }

    private function dockerCalls(): string
    {
        $calls = file_get_contents($this->directory.'/calls');
        self::assertIsString($calls);

        return $calls;
    }
}
