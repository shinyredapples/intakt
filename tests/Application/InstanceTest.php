<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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

        $status = $this->decoded($run['status']);
        self::assertSame('failed', $status['status']);
        self::assertStringContainsString('no_such_option', $status['reason']);
        self::assertSame(1, $run['exit']);
    }

    public function testAnAnswerWithAnUnparsableStatusLineReportsFailed(): void
    {
        $project = $this->copyOfTheProject();
        file_put_contents($project.'/public/index.php', "<?php\nheader('HTTP/1.1 99 Odd');\n");

        $run = $this->runInstance($project);

        $status = $this->decoded($run['status']);
        self::assertSame('failed', $status['status']);
        self::assertStringContainsString('HTTP/1.1 99 Odd', $status['reason']);
        self::assertSame(1, $run['exit']);
    }

    public function testTheServerNeverHoldsTheStatusDescriptor(): void
    {
        $statusFile = $this->scratch.'/status';
        $caller = $this->callerHoldingManyDescriptors($statusFile);

        $caller->start();
        $held = $this->descriptorsOfTheServerWhile($caller);

        self::assertNotNull($held, 'the server was never seen running');
        self::assertNotContains(realpath($statusFile), $held);
        self::assertSame('{"status":"ready"}', trim((string) file_get_contents($statusFile)));
    }

    public function testNothingIsLeftBehindInTheTemporaryDirectoryAfterABoot(): void
    {
        $this->runInstance(self::ROOT);

        self::assertSame([], $this->entriesOf($this->scratch.'/tmp'));
    }

    public function testTheProjectTreeIsUnchangedAfterABoot(): void
    {
        $project = $this->copyOfTheProject();
        $before = $this->treeOf($project);

        $this->runInstance($project);

        self::assertSame($before, $this->treeOf($project));
    }

    public function testWithoutDescriptorThreeNoStatusIsWrittenAndTheExitIsNonZero(): void
    {
        $process = new Process([PHP_BINARY, self::ROOT.'/bin/instance'], null, ['TMPDIR' => $this->scratch.'/tmp']);
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
        $command = 'exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($project.'/bin/instance').' 3>'.escapeshellarg($statusFile);
        $process = Process::fromShellCommandline($command, null, $this->environment());
        $process->run();

        return ['status' => trim((string) file_get_contents($statusFile)), 'exit' => (int) $process->getExitCode()];
    }

    /**
     * @return array{status: string, reason: string}
     */
    private function decoded(string $statusLine): array
    {
        $status = json_decode($statusLine, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($status);
        self::assertIsString($status['status'] ?? null);
        self::assertIsString($status['reason'] ?? null);

        return ['status' => $status['status'], 'reason' => $status['reason']];
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        return ['TMPDIR' => $this->scratch.'/tmp', 'APP_ENV' => 'test'];
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

        new Filesystem()->remove($project.'/config/reference.php');

        return $project;
    }

    // A caller holding descriptors 3 to 14 pushes any duplicate of descriptor 3 above 9.
    private function callerHoldingManyDescriptors(string $statusFile): Process
    {
        $command = 'exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::ROOT.'/bin/instance').' 3>'.escapeshellarg($statusFile);
        $caller = 'for ($i = 0; $i < 12; ++$i) { $held[] = fopen("/dev/null", "r"); } passthru('.var_export($command, true).');';

        return new Process([PHP_BINARY, '-r', $caller], null, $this->environment());
    }

    /**
     * @return list<string>|null the targets of every descriptor the server held, null when it was never seen
     */
    private function descriptorsOfTheServerWhile(Process $caller): ?array
    {
        $held = null;

        while ($caller->isRunning()) {
            foreach ($this->serverProcesses() as $directory) {
                $held = [...$held ?? [], ...$this->descriptorTargets($directory)];
            }

            usleep(20_000);
        }

        return $held;
    }

    /**
     * @return list<string>
     */
    private function serverProcesses(): array
    {
        $public = realpath(self::ROOT).'/public';

        return array_values(array_filter(
            glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [],
            static function (string $directory) use ($public): bool {
                $arguments = explode("\0", (string) @file_get_contents($directory.'/cmdline'));

                return PHP_BINARY === $arguments[0] && in_array('-S', $arguments, true) && in_array($public, $arguments, true);
            },
        ));
    }

    /**
     * @return list<string>
     */
    private function descriptorTargets(string $process): array
    {
        $targets = [];

        foreach (glob($process.'/fd/*') ?: [] as $descriptor) {
            $target = @readlink($descriptor);
            $targets[] = false === $target ? '' : $target;
        }

        return $targets;
    }

    /**
     * @return array<string, int|false>
     */
    private function treeOf(string $project): array
    {
        $tree = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($project, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        foreach (array_keys(iterator_to_array($files)) as $path) {
            if (is_string($path) && !str_starts_with($path, $project.'/vendor')) {
                $tree[$path] = is_file($path) ? filemtime($path) : 0;
            }
        }

        ksort($tree);

        return $tree;
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
