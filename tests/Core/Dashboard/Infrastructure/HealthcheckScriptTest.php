<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// Runs the image's health script with a stand-in for curl, because what is proven here is when the container is called healthy, not the server's answer.
#[CoversNothing]
final class HealthcheckScriptTest extends TestCase
{
    private string $directory;

    public function testTheHealthCheckFailsWhileTheServerDoesNotAnswer(): void
    {
        $process = $this->check(['FAIL_ON' => 'http://localhost:2019/metrics']);

        self::assertNotSame(0, $process->getExitCode());
    }

    public function testTheHealthCheckFailsWhileTheDashboardDoesNotAnswer(): void
    {
        $process = $this->check(['FAIL_ON' => 'http://localhost:7781/']);

        self::assertNotSame(0, $process->getExitCode());
    }

    public function testTheHealthCheckFailsBeforeTheSampleIsSeededEvenWhenTheDashboardAnswers(): void
    {
        $process = $this->check(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample']);

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('not seeded', $process->getErrorOutput());
    }

    public function testTheHealthCheckPassesOnceTheDashboardAnswersAndTheSeedHasFinished(): void
    {
        new Filesystem()->touch($this->directory.'/var/sample-ready');

        $process = $this->check(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testTheHealthCheckPassesWithoutASampleOnceTheServerAndTheDashboardAnswer(): void
    {
        $process = $this->check([]);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testTheHealthCheckCallsTheServerPortWithThePublishedPortAsHost(): void
    {
        $process = $this->check(['SERVER_PORT' => '7781', 'DASHBOARD_PORT' => '8080']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $calls = (string) file_get_contents($this->directory.'/calls');
        self::assertStringContainsString('-H Host: localhost:8080 http://localhost:7781/', $calls);
        self::assertStringNotContainsString('8080/', $calls);
    }

    public function testWithBothPortsAtTheirDefaultTheHealthCheckCallsLocalhost7781AsBefore(): void
    {
        $process = $this->check(['SERVER_PORT' => false, 'DASHBOARD_PORT' => false]);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('-H Host: localhost:7781 http://localhost:7781/', (string) file_get_contents($this->directory.'/calls'));
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-healthcheck-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->directory.'/stubs/curl', "#!/bin/bash\necho \"\$*\" >> \"\$CALLS\"\n[[ \" \$* \" == *\" \$FAIL_ON \"* ]] && exit 22\nexit 0\n");
        $filesystem->chmod($this->directory.'/stubs/curl', 0755);
        $filesystem->mkdir($this->directory.'/var');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    /**
     * @param array<string, false|string> $environment
     */
    private function check(array $environment): Process
    {
        $process = new Process([dirname(__DIR__, 4).'/docker/healthcheck.sh'], $this->directory, [
            'PATH' => $this->directory.'/stubs:'.getenv('PATH'),
            'CALLS' => $this->directory.'/calls',
            'SERVER_PORT' => '7781',
            'DASHBOARD_PORT' => '7781',
            'CADDY_ADMIN' => false,
            'INTAKT_SAMPLE_REPOSITORY' => false,
            'FAIL_ON' => '-none-',
            ...$environment,
        ]);
        $process->run();

        return $process;
    }
}
