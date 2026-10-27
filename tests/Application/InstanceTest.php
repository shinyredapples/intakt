<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
#[Group('instance')]
final class InstanceTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().'/intakt-instance-test-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->scratch.'/tmp');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->scratch);
    }

    public function testACleanCheckoutReportsReadyAndExitsZero(): void
    {
        $run = $this->runInstance(self::ROOT);

        self::assertSame('{"status":"ready"}', $run['status']);
        self::assertSame(0, $run['exit']);
    }

    public function testABrokenKernelConfigReportsFailedWithTheErrorAndExitsNonZero(): void
    {
        $project = $this->copyOfTheProject();
        file_put_contents($project.'/config/packages/broken.yaml', "framework:\n    no_such_option: true\n");

        $run = $this->runInstance($project);

        $status = json_decode($run['status'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($status);
        self::assertSame('failed', $status['status']);
        self::assertIsString($status['reason'] ?? null);
        self::assertStringContainsString('no_such_option', $status['reason']);
        self::assertSame(1, $run['exit']);
    }

    public function testACallerHoldingManyDescriptorsStillGetsAReadyBoot(): void
    {
        $statusFile = $this->scratch.'/status';
        $caller = \sprintf(
            'for ($i = 0; $i < 12; ++$i) { $held[] = fopen("/dev/null", "r"); } passthru("exec %s %s 3>%s");',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(self::ROOT.'/bin/instance'),
            escapeshellarg($statusFile),
        );
        $process = new Process([\PHP_BINARY, '-r', $caller], null, ['TMPDIR' => $this->scratch.'/tmp', 'APP_ENV' => 'test']);
        $process->run();

        self::assertSame('{"status":"ready"}', trim((string) file_get_contents($statusFile)));
    }

    public function testNothingIsLeftBehindAfterABoot(): void
    {
        $this->runInstance(self::ROOT);

        self::assertSame([], $this->entriesOf($this->scratch.'/tmp'));
    }

    public function testWithoutDescriptorThreeNoStatusIsWrittenAndTheExitIsNonZero(): void
    {
        $process = new Process([\PHP_BINARY, self::ROOT.'/bin/instance'], null, ['TMPDIR' => $this->scratch.'/tmp']);
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringNotContainsString('"status"', $process->getOutput());
    }

    /**
     * @return array{status: string, exit: int}
     */
    private function runInstance(string $project): array
    {
        $statusFile = $this->scratch.'/status';
        $command = \sprintf('exec %s %s 3>%s', escapeshellarg(\PHP_BINARY), escapeshellarg($project.'/bin/instance'), escapeshellarg($statusFile));
        $process = Process::fromShellCommandline($command, null, ['TMPDIR' => $this->scratch.'/tmp', 'APP_ENV' => 'test']);
        $process->run();

        return ['status' => trim((string) file_get_contents($statusFile)), 'exit' => (int) $process->getExitCode()];
    }

    private function copyOfTheProject(): string
    {
        $project = $this->scratch.'/project';
        new Filesystem()->mkdir($project);

        // Hard links keep the vendor copy cheap, and a real copy keeps its autoloader pointing at the copy of src.
        $copy = new Process(['sh', '-c', 'cp -al "$0"/vendor "$1"/vendor 2>/dev/null || cp -a "$0"/vendor "$1"/vendor', self::ROOT, $project]);
        $copy->mustRun();

        foreach (['bin', 'src', 'config', 'public'] as $directory) {
            new Filesystem()->mirror(self::ROOT.'/'.$directory, $project.'/'.$directory);
        }

        foreach (['composer.json', '.env'] as $file) {
            new Filesystem()->copy(self::ROOT.'/'.$file, $project.'/'.$file);
        }

        return $project;
    }

    /**
     * @return list<string>
     */
    private function entriesOf(string $directory): array
    {
        $found = scandir($directory);

        return array_values(array_diff(false === $found ? [] : $found, ['.', '..']));
    }
}
