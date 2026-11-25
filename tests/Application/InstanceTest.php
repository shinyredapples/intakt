<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversNothing]
#[Group('instance')]
final class InstanceTest extends InstanceTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::copyTheStubProject();
    }

    // One boot for both, because each boot of the real kernel compiles its container cold.
    public function testACleanCopyOfTheProjectReportsReadyExitsZeroAndLeavesNothingInTheTemporaryDirectoryOrTheProjectTree(): void
    {
        $project = $this->copyOfTheProject();
        $before = $this->treeOf($project);

        $run = $this->runInstance($project);

        self::assertSame('{"status":"ready"}', $run['status']);
        self::assertSame(0, $run['exit']);
        self::assertSame([], $this->entriesOf($this->scratch.'/tmp'));
        self::assertSame($before, $this->treeOf($project));
    }

    public function testABrokenKernelConfigReportsFailedPrintsTheServerLogAndNeverRunsTheCommand(): void
    {
        $project = $this->copyOfTheProject();
        file_put_contents($project.'/config/packages/broken.yaml', "framework:\n    no_such_option: true\n");

        $run = $this->runInstance($project, [PHP_BINARY, '-r', 'echo "command ran\n";']);

        $status = $this->decoded($run['status']);
        self::assertSame('failed', $status['status']);
        self::assertStringContainsString('no_such_option', $status['reason']);
        self::assertStringContainsString('no_such_option', $run['output']);
        self::assertStringNotContainsString('command ran', $run['output']);
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

    public function testAnHttp500WhoseLogHoldsOnlyTheBuiltInServersOwnLinesReportsThatTheLogNamesNoReason(): void
    {
        $project = $this->copyOfTheProject();
        file_put_contents($project.'/public/index.php', "<?php\nhttp_response_code(500);\n");

        $run = $this->runInstance($project);

        self::assertSame('HTTP 500: the server log names no reason', $this->decoded($run['status'])['reason']);
    }

    public function testAnHttp500ReportsTheLastLogLineTheBuiltInServerDidNotWriteAboutItself(): void
    {
        $project = $this->copyOfTheProject();
        file_put_contents($project.'/public/index.php', "<?php\nfile_put_contents('php://stderr', \"the disk is full\\n\");\nhttp_response_code(500);\n");

        $run = $this->runInstance($project);

        self::assertSame('HTTP 500: the disk is full', $this->decoded($run['status'])['reason']);
    }

    public function testTheVarFolderItCreatedWithoutTmpdirIsGoneAfterABoot(): void
    {
        $project = $this->stubWithoutVarFolder();

        $run = $this->runInstance($project, environment: ['TMPDIR' => false]);

        self::assertSame('{"status":"ready"}', $run['status']);
        self::assertDirectoryDoesNotExist($project.'/var');
    }

    public function testAVarFolderThatAlreadyExistedStaysWithItsFilesAfterABootWithoutTmpdir(): void
    {
        $project = $this->stubWithoutVarFolder();
        mkdir($project.'/var');
        file_put_contents($project.'/var/keep.txt', 'kept');

        $run = $this->runInstance($project, environment: ['TMPDIR' => false]);

        self::assertSame('{"status":"ready"}', $run['status']);
        self::assertSame('kept', file_get_contents($project.'/var/keep.txt'));
    }

    public function testAnEmptyVarFolderThatAlreadyExistedStaysAfterABootWithoutTmpdir(): void
    {
        $project = $this->stubWithoutVarFolder();
        mkdir($project.'/var');

        $run = $this->runInstance($project, environment: ['TMPDIR' => false]);

        self::assertSame('{"status":"ready"}', $run['status']);
        self::assertDirectoryExists($project.'/var');
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

    public function testACommandRunsOnceTheInstanceIsReadyWithOnlyTheAllowedEnvironmentAndItsExitCodePassesThrough(): void
    {
        // One boot for all command-mode facts of the built-in server, because each boot costs a server start.
        $command = <<<'PHP'
            $lines = [
                (string) getenv("INTAKT_INSTANCE_URL"),
                "admin url: ".var_export(getenv("INTAKT_INSTANCE_ADMIN_URL"), true),
                "server admin url: ".file_get_contents(getenv("INTAKT_INSTANCE_URL")."/?admin"),
                "descriptor 3: ".(@readlink("/proc/self/fd/3") === realpath($argv[2]) ? "status" : "other"),
            ];
            foreach (getenv() as $name => $value) { $lines[] = $name."=".$value; }
            $output = implode("\n", $lines)."\n";
            file_put_contents($argv[1], $output);
            echo $output;
            exit(5);
            PHP;
        $printed = $this->scratch.'/printed';

        $run = $this->runInstance(self::stubProject(), [PHP_BINARY, '-r', $command, $printed, $this->scratch.'/status'], ['INTAKT_TEST_CANARY' => 'visible', 'FRANKENPHP_ADMIN_URL' => 'http://localhost:2019']);

        self::assertSame('{"status":"ready"}', $run['status']);
        self::assertSame(5, $run['exit']);
        self::assertMatchesRegularExpression('#^http://127\.0\.0\.1:\d+$#m', $run['output']);
        self::assertStringNotContainsString('INTAKT_TEST_CANARY', $run['output']);
        self::assertStringNotContainsString('visible', $run['output']);
        self::assertMatchesRegularExpression('/^PATH=./m', $run['output']);
        self::assertMatchesRegularExpression('#^INTAKT_INSTANCE_URL=http://127\.0\.0\.1:\d+$#m', $run['output']);
        self::assertStringEqualsFile($printed, $run['output']);
        // PHP itself may take the free descriptor 3 (OPcache does for its lock), so the target tells, not whether it is open.
        self::assertStringContainsString("\ndescriptor 3: other\n", $run['output']);
        self::assertStringContainsString("\nadmin url: false\n", $run['output']);
        self::assertStringContainsString("\nserver admin url: ok http://admin-api.invalid\n", $run['output']);
        self::assertStringNotContainsString('localhost:2019', $run['output']);
    }

    public function testACommandThatOverrunsItsTimeIsKilledWithExitCode124(): void
    {
        $run = $this->runInstance(self::stubProject(), ['sleep', '30'], ['INTAKT_INSTANCE_COMMAND_SECONDS' => '1']);

        self::assertSame(124, $run['exit']);
        self::assertStringContainsString('ran longer than 1 seconds and was killed', $run['errors']);
        self::assertSame('{"status":"ready"}', $run['status']);
    }

    public function testACommandThatOverrunsAFractionalLimitIsKilledWithExitCode124AndTheLimitNamed(): void
    {
        $run = $this->runInstance(self::stubProject(), ['sleep', '30'], ['INTAKT_INSTANCE_COMMAND_SECONDS' => '0.2']);

        self::assertSame(124, $run['exit']);
        self::assertStringContainsString('ran longer than 0.2 seconds and was killed', $run['errors']);
    }

    public function testACommandThatEndsDuringTheLastWaitBeforeAFractionalLimitIsNotKilled(): void
    {
        // Ends well inside the 0.2 second limit, because a loaded runner may start the command late and only its end may wake the wait.
        $run = $this->runInstance(self::stubProject(), ['sh', '-c', 'sleep 0.05; exit 3'], ['INTAKT_INSTANCE_COMMAND_SECONDS' => '0.2']);

        self::assertSame(3, $run['exit']);
        self::assertStringNotContainsString('was killed', $run['errors']);
    }

    public function testWhileTheCommandRunsTheInstanceSleepsUntilTheCommandEndsInsteadOfPolling(): void
    {
        // Because a polling wait also finds a quick exit, only the parent's voluntary wakes (not the nonvoluntary line) tell it apart: a 50 millisecond poll wakes many times in 0.9 seconds, the signal wait at most once a second.
        $count = 'awk \'$1 == "voluntary_ctxt_switches:" { print $2 }\' /proc/$PPID/status';
        $run = $this->runInstance(self::stubProject(), ['sh', '-c', 'before=$('.$count.'); sleep 0.9; after=$('.$count.'); echo "wakes: $((after - before))"'], ['INTAKT_INSTANCE_COMMAND_SECONDS' => '5']);

        self::assertSame(0, $run['exit']);
        self::assertMatchesRegularExpression('/^wakes: [0-3]$/m', $run['output']);
    }

    public function testWithoutTheSignalFunctionsAnOverrunningCommandIsStillKilledByThePollingWait(): void
    {
        $settings = $this->scratch.'/ini';
        mkdir($settings);
        file_put_contents($settings.'/no-signals.ini', "disable_functions = pcntl_sigprocmask,pcntl_sigtimedwait\n");

        // A leading separator keeps the default scan folder, so the extensions stay loaded.
        $run = $this->runInstance(self::stubProject(), ['sleep', '30'], ['INTAKT_INSTANCE_COMMAND_SECONDS' => '0.2', 'PHP_INI_SCAN_DIR' => ':'.$settings]);

        self::assertSame(124, $run['exit']);
        self::assertStringContainsString('ran longer than 0.2 seconds and was killed', $run['errors']);
    }

    public function testACommandLimitThatIsNotFiniteInNanosecondsIsRefusedBeforeTheCommandRuns(): void
    {
        $marker = $this->scratch.'/ran';

        $run = $this->runInstance(self::stubProject(), ['touch', $marker], ['INTAKT_INSTANCE_COMMAND_SECONDS' => '1e300']);

        self::assertSame(1, $run['exit']);
        self::assertSame('failed', $this->decoded($run['status'])['status']);
        self::assertStringContainsString('must be a finite number of nanoseconds', $this->decoded($run['status'])['reason']);
        self::assertFileDoesNotExist($marker);
    }

    #[DataProvider('signals')]
    public function testAConsoleCommandStoppedBySignalExitsWith128PlusTheSignalNumberThroughTheInstance(int $signal, int $expected): void
    {
        $command = <<<'PHP'
            use Symfony\Component\Console\Application;
            use Symfony\Component\Console\Command\Command;
            use Symfony\Component\Console\Command\SignalableCommandInterface;
            use Symfony\Component\Console\Input\ArrayInput;
            use Symfony\Component\Console\Input\InputInterface;
            use Symfony\Component\Console\Output\OutputInterface;
            use Symfony\Component\EventDispatcher\EventDispatcher;

            require $argv[1].'/vendor/autoload.php';
            $command = new class('wait') extends Command implements SignalableCommandInterface {
                public function getSubscribedSignals(): array { return [SIGINT, SIGTERM]; }
                public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false { return $previousExitCode; }
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    posix_kill(getmypid(), (int) $_SERVER['argv'][2]);
                    pcntl_signal_dispatch();
                    sleep(5);

                    return 0;
                }
            };
            $application = new Application();
            $application->addCommand($command);
            $application->setDispatcher(new EventDispatcher());
            $application->setAutoExit(false);
            exit($application->run(new ArrayInput(['command' => 'wait'])));
            PHP;

        $run = $this->runInstance(self::stubProject(), [PHP_BINARY, '-r', $command, self::ROOT, (string) $signal]);

        self::assertSame($expected, $run['exit']);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function signals(): iterable
    {
        yield 'interrupt' => [SIGINT, 130];
        yield 'terminate' => [SIGTERM, 143];
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
}
