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

    // Vendor is hard-linked once per class (a symlink would resolve the project to the real checkout, and a link that fails partway across file systems never counts as vendor), because no test writes to it and linking it cost over half a second per test. It is kept only while its installed packages are the checkout's (the same installed.json content), because a folder left by a killed run with the same process id may hold the vendor of an older checkout, and the autoload file keeps its inode across an in-place dependency update, so a copy that could not be linked is reused too.
    private const string COPY = <<<'SH'
        set -e
        project=$1
        shift
        cd "$project"
        find . -mindepth 1 -maxdepth 1 ! -name vendor -exec rm -rf {} +
        if ! cmp -s vendor/composer/installed.json "$0"/vendor/composer/installed.json 2>/dev/null; then
            rm -rf vendor vendor.partial
            cp -al "$0"/vendor vendor.partial 2>/dev/null || { rm -rf vendor.partial && cp -a "$0"/vendor vendor.partial; }
            mv vendor.partial vendor
        fi
        cd "$0"
        cp -a "$@" "$project"/
        SH;

    // An instance per test, not per class, because each boot builds its container in a scratch folder it removes when it stops, which is what several tests prove, and most tests boot with another project, command or environment.
    private string $scratch;

    public static function tearDownAfterClass(): void
    {
        new Filesystem()->remove(self::sharedProject());
    }

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

    public function testAVendorLeftByAnEarlierRunOfTheSharedProjectIsReplacedByTheCheckouts(): void
    {
        $vendor = self::sharedProject().'/vendor';
        new Filesystem()->remove($vendor);
        new Filesystem()->dumpFile($vendor.'/stale-marker', 'from an older checkout');

        $project = $this->copyOfTheProject();

        self::assertFileDoesNotExist($project.'/vendor/stale-marker');
        self::assertFileExists($project.'/vendor/autoload.php');
    }

    public function testAVendorWithOtherPackagesThanTheCheckoutsIsReplacedEvenWhenItsAutoloadFileIsTheCheckouts(): void
    {
        $vendor = $this->copyOfTheProject().'/vendor';
        new Filesystem()->remove($vendor.'/composer/installed.json');
        new Filesystem()->dumpFile($vendor.'/composer/installed.json', '[]');
        new Filesystem()->dumpFile($vendor.'/stale-marker', 'from an older checkout');

        $project = $this->copyOfTheProject();

        self::assertFileDoesNotExist($project.'/vendor/stale-marker');
        self::assertFileEquals(self::ROOT.'/vendor/composer/installed.json', $project.'/vendor/composer/installed.json');
    }

    public function testAVendorCopiedWithoutLinksIsReusedWhenItsPackagesAreTheCheckouts(): void
    {
        $autoload = $this->copyOfTheProject().'/vendor/autoload.php';
        new Filesystem()->remove($autoload);
        new Filesystem()->copy(self::ROOT.'/vendor/autoload.php', $autoload);
        clearstatcache();
        $copied = fileinode($autoload);

        $project = $this->copyOfTheProject();

        clearstatcache();
        self::assertSame($copied, fileinode($project.'/vendor/autoload.php'));
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

    public function testWithoutFrankenphpACommandGetsNoAdminUrl(): void
    {
        $run = $this->runInstance(self::ROOT, [PHP_BINARY, '-r', 'echo var_export(getenv("INTAKT_INSTANCE_ADMIN_URL"), true), "\n";']);

        self::assertSame('{"status":"ready"}', $run['status']);
        self::assertSame("false\n", $run['output']);
    }

    public function testWithFrankenphpOnThePathTheBootReportsReadyInWorkerMode(): void
    {
        $run = $this->runInstance(self::ROOT, environment: $this->environmentWithFrankenphp());

        self::assertSame('{"status":"ready","mode":"worker"}', $run['status']);
        self::assertSame(0, $run['exit']);
    }

    public function testInWorkerModeTheAppWorkerServedTheReadinessRequest(): void
    {
        // FrankenPHP counts a request once the worker has finished it, after the answer is sent, so the command polls.
        $threads = 'for ($i = 0; $i < 100; ++$i) { '
            .'$states = json_decode((string) file_get_contents(getenv("INTAKT_INSTANCE_ADMIN_URL")."/frankenphp/threads"), true)["ThreadDebugStates"] ?? []; '
            .'foreach ($states as $state) { if (str_starts_with($state["Name"], "Worker ") && $state["RequestCount"] > 0) { echo $state["Name"], " served ", $state["RequestCount"], "\n"; exit(0); } } '
            .'usleep(100_000); } exit(3);';

        $run = $this->runInstance(self::ROOT, [PHP_BINARY, '-r', $threads], $this->environmentWithFrankenphp());

        self::assertSame('{"status":"ready","mode":"worker"}', $run['status']);
        self::assertMatchesRegularExpression('#^Worker PHP Thread - '.preg_quote((string) realpath(self::ROOT), '#').'/public/index\.php served [1-9]\d*$#m', $run['output']);
        self::assertSame(0, $run['exit']);
    }

    public function testInWorkerModeTheHttpListenerIsBoundToLoopbackOnly(): void
    {
        // A Caddy site address only matches the Host header, so the kernel's socket table tells where the listener really is.
        $listeners = '$port = (int) parse_url((string) getenv("INTAKT_INSTANCE_URL"), PHP_URL_PORT); $addresses = []; '
            .'foreach (["/proc/net/tcp", "/proc/net/tcp6"] as $table) { foreach (file($table) ?: [] as $line) { $columns = preg_split("/\s+/", trim($line)); '
            .'if ("0A" !== ($columns[3] ?? "")) { continue; } [$address, $hexPort] = explode(":", $columns[1]); if (hexdec($hexPort) === $port) { $addresses[] = $address; } } } '
            .'echo implode("\n", array_unique($addresses)), "\n";';

        $run = $this->runInstance(self::ROOT, [PHP_BINARY, '-r', $listeners], $this->environmentWithFrankenphp());

        self::assertSame('{"status":"ready","mode":"worker"}', $run['status']);
        self::assertSame("0100007F\n", $run['output']);
    }

    public function testInWorkerModeABrokenKernelConfigReportsFailedWithTheErrorAndExitsNonZero(): void
    {
        $project = $this->copyOfTheProject();
        file_put_contents($project.'/config/packages/broken.yaml', "framework:\n    no_such_option: true\n");

        $run = $this->runInstance($project, environment: $this->environmentWithFrankenphp());

        $status = $this->decoded($run['status']);
        self::assertSame('failed', $status['status']);
        self::assertStringContainsString('"mode":"worker"', $run['status']);
        self::assertStringContainsString('no_such_option', $status['reason']);
        self::assertSame(1, $run['exit']);
    }

    public function testAFrankenphpFoundThroughARelativePathEntryBootsInWorkerModeFromAnotherDirectory(): void
    {
        $frankenphp = new ExecutableFinder()->find('frankenphp');
        self::assertNotNull($frankenphp, 'frankenphp must be on the PATH, as in the image and the build sandbox.');
        $caller = $this->scratch.'/caller';
        mkdir($caller.'/tools', recursive: true);
        symlink($frankenphp, $caller.'/tools/frankenphp');

        $run = $this->runInstanceFrom($caller, ['PATH' => 'tools:'.$this->pathWithoutFrankenphp()]);

        self::assertSame('{"status":"ready","mode":"worker"}', $run['status']);
        self::assertSame(0, $run['exit']);
    }

    public function testAFrankenphpInTheCallersDirectoryIsFoundThroughAnEmptyPathEntry(): void
    {
        $frankenphp = new ExecutableFinder()->find('frankenphp');
        self::assertNotNull($frankenphp, 'frankenphp must be on the PATH, as in the image and the build sandbox.');
        $caller = $this->scratch.'/caller';
        mkdir($caller);
        symlink($frankenphp, $caller.'/frankenphp');

        $run = $this->runInstanceFrom($caller, ['PATH' => ':'.$this->pathWithoutFrankenphp()]);

        self::assertSame('{"status":"ready","mode":"worker"}', $run['status']);
        self::assertSame(0, $run['exit']);
    }

    public function testNothingIsLeftBehindAfterAWorkerModeBoot(): void
    {
        $this->runInstance(self::ROOT, environment: $this->environmentWithFrankenphp());

        self::assertSame([], $this->entriesOf($this->scratch.'/tmp'));
        self::assertSame([], $this->processesNaming($this->scratch.'/tmp'));
    }

    public function testAFrankenphpThatFailsToStartReportsFailedWithItsReasonAndLeavesNothing(): void
    {
        $stubs = $this->scratch.'/stubs';
        mkdir($stubs);
        file_put_contents($stubs.'/frankenphp', "#!/bin/sh\necho 'Error: loading initial config: listen tcp 127.0.0.1:1: bind: address already in use' >&2\nexit 1\n");
        chmod($stubs.'/frankenphp', 0o755);

        $run = $this->runInstance(self::ROOT, environment: ['PATH' => $stubs.':'.$this->pathWithoutFrankenphp()]);

        $status = json_decode($run['status'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($status);
        self::assertSame(['status', 'mode', 'reason'], array_keys($status));
        self::assertSame(['failed', 'worker'], [$status['status'], $status['mode']]);
        self::assertSame('the server exited: Error: loading initial config: listen tcp 127.0.0.1:1: bind: address already in use', $status['reason']);
        self::assertSame(1, $run['exit']);
        self::assertSame([], $this->entriesOf($this->scratch.'/tmp'));
        self::assertSame([], $this->processesNaming($this->scratch.'/tmp'));
    }

    public function testAnAnswerOnTheChosenPortBeforeFrankenphpServesDoesNotMakeTheInstanceReady(): void
    {
        // The stub answers one request on the port like an unrelated listener would, then fails to bind as the real FrankenPHP does behind it.
        $stubs = $this->scratch.'/stubs';
        mkdir($stubs);
        file_put_contents($stubs.'/frankenphp', '#!'.PHP_BINARY."\n".<<<'PHP'
            <?php
            preg_match('~http://127\.0\.0\.1:(\d+)~', (string) file_get_contents($argv[array_search('--config', $argv, true) + 1]), $match);
            $listener = stream_socket_server('tcp://127.0.0.1:'.$match[1]);
            $connection = stream_socket_accept($listener, 3);
            if (false !== $connection) {
                fread($connection, 8192);
                fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                fclose($connection);
            }
            fwrite(STDERR, 'Error: loading initial config: listen tcp 127.0.0.1:'.$match[1].": bind: address already in use\n");
            exit(1);
            PHP);
        chmod($stubs.'/frankenphp', 0o755);

        $run = $this->runInstance(self::ROOT, [PHP_BINARY, '-r', 'echo "command ran\n";'], ['PATH' => $stubs.':'.$this->pathWithoutFrankenphp()]);

        $status = $this->decoded($run['status']);
        self::assertSame('failed', $status['status']);
        self::assertMatchesRegularExpression('/^the server exited: Error: loading initial config: listen tcp 127\.0\.0\.1:\d+: bind: address already in use$/', $status['reason']);
        self::assertStringNotContainsString('command ran', $run['output']);
        self::assertSame(1, $run['exit']);
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

    public function testTheMessageNamesAnUnknownReasonWhenMkdirFailsWithoutAWarning(): void
    {
        $prepend = $this->scratch.'/silent_mkdir_failure.php';
        file_put_contents($prepend, <<<'PHP'
            <?php
            final class SilentMkdirFailure
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
                    return false;
                }
            }
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', SilentMkdirFailure::class);
            PHP);

        $run = $this->runInstanceWithPhpOptions(['-d', 'auto_prepend_file='.$prepend]);

        $status = $this->decoded($run['status']);
        self::assertSame('failed', $status['status']);
        self::assertSame('cannot create the scratch directory: unknown reason', $status['reason']);
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
     * @param array<string, string|false> $environment
     *
     * @return array{status: string, exit: int, output: string, errors: string}
     */
    private function runInstanceFrom(string $directory, array $environment): array
    {
        $statusFile = $this->scratch.'/status';
        $shell = 'exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::ROOT.'/bin/instance').' 3>'.escapeshellarg($statusFile);
        $process = Process::fromShellCommandline($shell, $directory, [...$this->environment(), ...$environment]);
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
        $process = Process::fromShellCommandline($shell, null, ['TMPDIR' => $this->scratch.'/tmp', 'PATH' => $this->pathWithoutFrankenphp()]);
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
        // Without frankenphp on the PATH, so these runs cover the built-in server wherever they run.
        return ['TMPDIR' => $this->scratch.'/tmp', 'APP_ENV' => 'test', 'PATH' => $this->pathWithoutFrankenphp()];
    }

    /**
     * @return array<string, string>
     */
    private function environmentWithFrankenphp(): array
    {
        $path = (string) getenv('PATH');
        self::assertNotNull(new ExecutableFinder()->find('frankenphp'), 'frankenphp must be on the PATH, as in the image and the build sandbox.');

        return ['PATH' => $path];
    }

    private function pathWithoutFrankenphp(): string
    {
        $directories = array_filter(explode(':', (string) getenv('PATH')), static fn (string $directory): bool => !is_file($directory.'/frankenphp'));

        return implode(':', $directories);
    }

    /**
     * @return list<string>
     */
    private function processesNaming(string $path): array
    {
        return array_values(array_filter(
            $this->matching('/proc/[0-9]*'),
            static fn (string $directory): bool => str_contains((string) @file_get_contents($directory.'/cmdline'), $path),
        ));
    }

    private function copyOfTheProject(): string
    {
        $project = self::sharedProject();
        new Filesystem()->mkdir($project);
        $copy = new Process(['sh', '-c', self::COPY, self::ROOT, $project, 'bin', 'src', 'config', 'public', 'composer.json', '.env']);
        $copy->mustRun();
        new Filesystem()->remove($project.'/config/reference.php');

        return $project;
    }

    // Named by the process, because the copy outlives a test while two runs of this class side by side must not share it.
    private static function sharedProject(): string
    {
        return sys_get_temp_dir().'/intakt-instance-test-project-'.getmypid();
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
