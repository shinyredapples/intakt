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
use Symfony\Component\Process\ExecutableFinder;
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

    public function testTheVarFolderItCreatedWithoutTmpdirIsGoneAfterABoot(): void
    {
        $project = $this->copyOfTheProject();

        $run = $this->runInstance($project, environment: ['TMPDIR' => false]);

        self::assertSame('{"status":"ready"}', $run['status']);
        self::assertDirectoryDoesNotExist($project.'/var');
    }

    public function testAVarFolderThatAlreadyExistedStaysWithItsFilesAfterABootWithoutTmpdir(): void
    {
        $project = $this->copyOfTheProject();
        mkdir($project.'/var');
        file_put_contents($project.'/var/keep.txt', 'kept');

        $run = $this->runInstance($project, environment: ['TMPDIR' => false]);

        self::assertSame('{"status":"ready"}', $run['status']);
        self::assertSame('kept', file_get_contents($project.'/var/keep.txt'));
    }

    public function testAnEmptyVarFolderThatAlreadyExistedStaysAfterABootWithoutTmpdir(): void
    {
        $project = $this->copyOfTheProject();
        mkdir($project.'/var');

        $run = $this->runInstance($project, environment: ['TMPDIR' => false]);

        self::assertSame('{"status":"ready"}', $run['status']);
        self::assertDirectoryExists($project.'/var');
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
        $process = $this->runInstanceWithoutDescriptorThree([]);

        self::assertSame(1, $process->getExitCode());
        self::assertStringNotContainsString('"status"', $process->getOutput());
    }

    public function testADescriptorThreeThatOpcacheOpenedForItsLockCountsAsMissing(): void
    {
        if (!extension_loaded('Zend OPcache')) {
            self::markTestSkipped('The Zend OPcache extension is not loaded, so nothing of PHP takes descriptor 3.');
        }

        $process = $this->runInstanceWithoutDescriptorThree(['-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1']);

        self::assertSame(1, $process->getExitCode());
        self::assertStringNotContainsString('"status"', $process->getOutput());
    }

    public function testAMemfdAsDescriptorThreeReceivesTheStatusLineAndTheExitIsZero(): void
    {
        $process = $this->runInstanceWithAMemfdNamed('status');

        self::assertSame('{"status":"ready"}', trim($process->getErrorOutput()));
        self::assertSame(0, $process->getExitCode());
    }

    public function testAMemfdWhoseNameOnlyStartsLikeOpcachesLockReceivesTheStatusLine(): void
    {
        $process = $this->runInstanceWithAMemfdNamed('opcache_lock_status');

        self::assertSame('{"status":"ready"}', trim($process->getErrorOutput()));
        self::assertSame(0, $process->getExitCode());
    }

    public function testAPipeAsDescriptorThreeReceivesTheStatusLine(): void
    {
        $shell = 'exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::ROOT.'/bin/instance').' 3>&1 >/dev/null';
        $process = Process::fromShellCommandline($shell, null, $this->environment());
        $process->run();

        self::assertSame('{"status":"ready"}', trim($process->getOutput()));
        self::assertSame(0, $process->getExitCode());
    }

    public function testACommandRunsOnceTheInstanceIsReadyAndItsExitCodePassesThrough(): void
    {
        $run = $this->runInstance(self::ROOT, [PHP_BINARY, '-r', 'echo getenv("INTAKT_INSTANCE_URL"), "\n"; exit(5);']);

        self::assertSame('{"status":"ready"}', $run['status']);
        self::assertMatchesRegularExpression('#^http://127\.0\.0\.1:\d+$#m', $run['output']);
        self::assertSame(5, $run['exit']);
    }

    public function testACommandDoesNotInheritAVariableThatIsNotOnTheAllowlist(): void
    {
        $run = $this->runInstance(self::ROOT, [PHP_BINARY, '-r', 'foreach (getenv() as $name => $value) { echo $name, "=", $value, "\n"; }'], ['INTAKT_TEST_CANARY' => 'visible']);

        self::assertStringNotContainsString('INTAKT_TEST_CANARY', $run['output']);
        self::assertStringNotContainsString('visible', $run['output']);
        self::assertMatchesRegularExpression('/^PATH=./m', $run['output']);
        self::assertMatchesRegularExpression('#^INTAKT_INSTANCE_URL=http://127\.0\.0\.1:\d+$#m', $run['output']);
    }

    public function testTheServerLogIsLeftOutOfACommandsOutputOnAReadyBoot(): void
    {
        $run = $this->runInstance(self::ROOT, [PHP_BINARY, '-r', 'echo "probe output\n";']);

        self::assertSame("probe output\n", $run['output']);
    }

    public function testTheServerLogIsPrintedAndTheCommandNotRunWhenTheBootFails(): void
    {
        $project = $this->copyOfTheProject();
        file_put_contents($project.'/config/packages/broken.yaml', "framework:\n    no_such_option: true\n");

        $run = $this->runInstance($project, [PHP_BINARY, '-r', 'echo "command ran\n";']);

        self::assertStringContainsString('no_such_option', $run['output']);
        self::assertStringNotContainsString('command ran', $run['output']);
        self::assertSame(1, $run['exit']);
    }

    public function testTheCommandDoesNotInheritTheStatusDescriptor(): void
    {
        // PHP itself may take the free descriptor 3 (OPcache does for its lock), so the target tells, not whether it is open.
        $run = $this->runInstance(self::ROOT, [PHP_BINARY, '-r', 'exit(@readlink("/proc/self/fd/3") === realpath($argv[1]) ? 9 : 0);', $this->scratch.'/status']);

        self::assertSame(0, $run['exit']);
        self::assertSame('{"status":"ready"}', $run['status']);
    }

    public function testACommandThatOverrunsItsTimeIsKilledWithExitCode124(): void
    {
        $run = $this->runInstance(self::ROOT, ['sleep', '30'], ['INTAKT_INSTANCE_COMMAND_SECONDS' => '1']);

        self::assertSame(124, $run['exit']);
        self::assertStringContainsString('ran longer than 1 seconds and was killed', $run['errors']);
        self::assertSame('{"status":"ready"}', $run['status']);
    }

    public function testTheOperatingSystemReasonIsInTheMessageWhenTheScratchFolderCannotBeCreated(): void
    {
        $notAFolder = $this->scratch.'/file';
        file_put_contents($notAFolder, 'x');

        $run = $this->runInstance(self::ROOT, environment: ['TMPDIR' => $notAFolder]);

        $status = $this->decoded($run['status']);
        self::assertSame('failed', $status['status']);
        self::assertMatchesRegularExpression('/^cannot create the scratch directory: mkdir\(\): .+/', $status['reason']);
        self::assertSame(1, $run['exit']);
    }

    public function testTheScratchFolderIsCreatedWhenTheFirstAttemptLosesTheRaceWithARmdir(): void
    {
        // The first mkdir must fail as if var/ had just been removed, because a real race cannot be forced; later calls reach the real file system.
        $prepend = $this->scratch.'/lose_first_mkdir.php';
        file_put_contents($prepend, <<<'PHP'
            <?php
            final class LoseFirstMkdir
            {
                public $context;

                public function url_stat(string $path, int $flags): array|false
                {
                    stream_wrapper_restore('file');
                    $result = @stat($path);
                    stream_wrapper_unregister('file');
                    stream_wrapper_register('file', self::class);

                    return $result;
                }

                public function mkdir(string $path, int $mode, int $options): bool
                {
                    stream_wrapper_restore('file');
                    trigger_error('mkdir(): No such file or directory', E_USER_WARNING);

                    return false;
                }
            }
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', LoseFirstMkdir::class);
            PHP);

        $run = $this->runInstanceWithPhpOptions(['-d', 'auto_prepend_file='.$prepend]);

        self::assertSame('{"status":"ready"}', $run['status']);
        self::assertSame(0, $run['exit']);
    }

    /**
     * @param list<string> $phpOptions
     *
     * @return array{status: string, exit: int}
     */
    private function runInstanceWithPhpOptions(array $phpOptions): array
    {
        $statusFile = $this->scratch.'/status';
        $shell = 'exec '.implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, ...$phpOptions, self::ROOT.'/bin/instance'])).' 3>'.escapeshellarg($statusFile);
        $process = Process::fromShellCommandline($shell, null, $this->environment());
        $process->run();

        return ['status' => trim((string) file_get_contents($statusFile)), 'exit' => (int) $process->getExitCode()];
    }

    /**
     * @param list<string>                $command
     * @param array<string, string|false> $environment
     *
     * @return array{status: string, exit: int, output: string, errors: string}
     */
    private function runInstance(string $project, array $command = [], array $environment = []): array
    {
        $statusFile = $this->scratch.'/status';
        $arguments = [] === $command ? [] : ['--', ...$command];
        $shell = 'exec '.implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, $project.'/bin/instance', ...$arguments])).' 3>'.escapeshellarg($statusFile);
        $process = Process::fromShellCommandline($shell, null, [...$this->environment(), ...$environment]);
        $process->run();

        return [
            'status' => trim((string) file_get_contents($statusFile)),
            'exit' => (int) $process->getExitCode(),
            'output' => $process->getOutput(),
            'errors' => $process->getErrorOutput(),
        ];
    }

    /**
     * @param list<string> $phpOptions
     */
    private function runInstanceWithoutDescriptorThree(array $phpOptions): Process
    {
        // PHPUnit's own descriptor 3 (Composer's bin proxy keeps the script open) would otherwise reach the child as a read-only file.
        $shell = 'exec '.implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, ...$phpOptions, self::ROOT.'/bin/instance'])).' 3>&-';
        $process = Process::fromShellCommandline($shell, null, ['TMPDIR' => $this->scratch.'/tmp']);
        $process->run();

        return $process;
    }

    private function runInstanceWithAMemfdNamed(string $name): Process
    {
        $python = new ExecutableFinder()->find('python3');
        if (null === $python) {
            self::markTestSkipped('python3 is not on the PATH, and the memfd helper needs it.');
        }

        $helper = 'import os, subprocess, sys; fd = os.memfd_create(sys.argv[1]); os.dup2(fd, 3); '
            .'code = subprocess.run(sys.argv[2:], pass_fds=(3,)).returncode; os.lseek(3, 0, 0); '
            .'sys.stderr.write(os.read(3, 4096).decode()); sys.exit(code)';
        $process = new Process([$python, '-I', '-c', $helper, $name, PHP_BINARY, self::ROOT.'/bin/instance'], null, $this->environment());
        $process->run();

        return $process;
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

        // Hard links keep the vendor copy cheap; across file systems they fail partway, and cp -a would nest into the partial folder.
        $copy = new Process(['sh', '-c', 'cp -al "$0"/vendor "$1"/vendor 2>/dev/null || { rm -rf "$1"/vendor && cp -a "$0"/vendor "$1"/vendor; }', self::ROOT, $project]);
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

    // Regression guard: the caller holds descriptors 3 to 14 so a duplicate of descriptor 3 above 9 would show up, which a shell redirection cannot name.
    private function callerHoldingManyDescriptors(string $statusFile): Process
    {
        $command = 'exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::ROOT.'/bin/instance').' 3>'.escapeshellarg($statusFile);
        $caller = 'for ($i = 0; $i < 12; ++$i) { $held[] = fopen("/dev/null", "r"); } passthru('.var_export($command, true).');';

        return new Process([PHP_BINARY, '-r', $caller], null, $this->environment());
    }

    /**
     * @return list<string>|null
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
            $this->matching('/proc/[0-9]*'),
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

        foreach ($this->matching($process.'/fd/*') as $descriptor) {
            $target = @readlink($descriptor);
            $targets[] = false === $target ? '' : $target;
        }

        return $targets;
    }

    /**
     * @return list<string>
     */
    private function matching(string $pattern): array
    {
        $paths = glob($pattern);

        return false === $paths ? [] : $paths;
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
