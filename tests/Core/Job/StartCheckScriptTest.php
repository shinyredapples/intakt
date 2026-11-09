<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Infrastructure\StartCheckReader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// Runs the script a job container starts with, against a real directory, with a stand-in curl that plays the proxy.
#[CoversNothing]
final class StartCheckScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../src/Core/Job/Infrastructure/start-check.sh';

    private string $folder = '';

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-script-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir([$this->folder.'/out', $this->folder.'/bin', $this->folder.'/in']);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    public function testAJobWhoseMountsAndVendorWorkReportsEveryItemPassedAndRunsItsCommand(): void
    {
        $this->curlExits(0);
        $process = $this->started('exit 0', $this->environment($this->folder.'/in'));

        self::assertSame(0, $process->getExitCode());
        self::assertSame("output\tok\t\ninput:{$this->folder}/in\tok\t\nvendor\tok\t\n", file_get_contents($this->folder.'/out/.start-check'));
        self::assertStringContainsString('start check: vendor ok', $process->getOutput());
        self::assertStringNotContainsString('REDUCED MODE', $process->getOutput());
        self::assertFileDoesNotExist($this->folder.'/out/.start-check-probe');
    }

    public function testABrokenInputMountIsReportedAndTheJobSaysItRunsInReducedMode(): void
    {
        $this->curlExits(0);
        $process = $this->started('echo "reduced=$INTAKT_REDUCED_MODE"', $this->environment($this->folder.'/missing'));

        self::assertStringContainsString("input:{$this->folder}/missing\tfail\tthe mount is missing or unreadable", (string) file_get_contents($this->folder.'/out/.start-check'));
        self::assertStringContainsString('REDUCED MODE: 1 start check item(s) failed', $process->getOutput());
        self::assertStringContainsString('reduced=1', $process->getOutput());
    }

    public function testAVendorTheProxyDoesNotReachIsReportedAndTheJobRunsInReducedMode(): void
    {
        $this->curlExits(56);
        $process = $this->started('exit 0', $this->environment($this->folder.'/in'));

        self::assertStringContainsString("vendor\tfail\tthe vendor is not reachable through the proxy", (string) file_get_contents($this->folder.'/out/.start-check'));
        self::assertStringContainsString('REDUCED MODE', $process->getOutput());
    }

    public function testAnOutputDirectoryThatCannotBeWrittenIsReportedInTheLogAndTheJobRunsInReducedMode(): void
    {
        $this->curlExits(0);
        $process = $this->started('exit 0', $this->environment($this->folder.'/in', $this->folder.'/not-there'));

        self::assertStringContainsString('start check: output failed', $process->getOutput());
        self::assertStringContainsString('REDUCED MODE', $process->getOutput());
    }

    public function testTheReportTheScriptWritesIsTheOneTheReaderParses(): void
    {
        $this->curlExits(56);
        $this->started('exit 0', $this->environment($this->folder.'/missing'));

        $spec = JobSpecBuilder::in($this->folder.'/out')->withInput($this->folder.'/missing', $this->folder.'/missing')->build();
        $failed = array_map(static fn ($item): string => $item->name, new StartCheckReader()->read($spec)->failed());

        self::assertSame(['input:'.$this->folder.'/missing', 'vendor'], $failed);
    }

    private function curlExits(int $code): void
    {
        file_put_contents($this->folder.'/bin/curl', "#!/bin/sh\nexit {$code}\n");
        chmod($this->folder.'/bin/curl', 0o755);
    }

    /**
     * @return array<string, string>
     */
    private function environment(string $input, ?string $output = null): array
    {
        return [
            'PATH' => $this->folder.'/bin:/usr/bin:/bin',
            'INTAKT_JOB_ID' => 'build-1',
            'INTAKT_OUTPUT_DIR' => $output ?? $this->folder.'/out',
            'INTAKT_INPUTS' => $input,
            'INTAKT_VENDOR_HOST' => 'api.vendor.example',
        ];
    }

    /**
     * @param array<string, string> $environment
     */
    private function started(string $command, array $environment): Process
    {
        $process = new Process(['sh', self::SCRIPT, 'sh', '-c', $command], null, $environment);
        $process->run();

        return $process;
    }
}
