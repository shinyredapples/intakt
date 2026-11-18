<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// A stand-in docker on the PATH, because the sandbox and the unit tests have no Docker; the real check runs in CI's image job.
#[CoversNothing]
final class VerifyFirstStartScriptTest extends TestCase
{
    private const string FAKE_DOCKER = <<<'BASH'
        #!/bin/bash
        echo "$*" >> "$(dirname "$0")/calls"
        BASH;

    // The CI image need not ship openssl, and the secrets only matter to a real container.
    private const string FAKE_OPENSSL = <<<'BASH'
        #!/bin/bash
        echo 00
        BASH;

    private string $directory;

    public function testWithoutAnImageTheScriptPrintsItsUsageAndExitsWithTwo(): void
    {
        $process = $this->verifyFirstStart();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('usage:', $process->getErrorOutput());
    }

    public function testTheScriptStartsTheGivenImageWithoutBuildingOne(): void
    {
        $process = $this->verifyFirstStart('intakt:ci');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringNotContainsString('build', $this->dockerCalls());
        self::assertMatchesRegularExpression('/^run --detach .* intakt:ci$/m', $this->dockerCalls());
    }

    public function testTheScriptShowsTheSeededPanelInTheRunningContainer(): void
    {
        $this->verifyFirstStart('intakt:ci');

        self::assertStringContainsString("exec intakt-first-start-check php bin/console intakt:review:panel sample\n", $this->dockerCalls());
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-first-start-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->directory.'/docker', self::FAKE_DOCKER);
        new Filesystem()->dumpFile($this->directory.'/openssl', self::FAKE_OPENSSL);
        chmod($this->directory.'/docker', 0o755);
        chmod($this->directory.'/openssl', 0o755);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    private function verifyFirstStart(string ...$arguments): Process
    {
        $process = new Process(['bash', 'docker/verify-first-start.sh', ...array_values($arguments)], dirname(__DIR__, 4), ['PATH' => $this->directory.':'.getenv('PATH')]);
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
