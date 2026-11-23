<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Tests\Support\ProjectCopy;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
#[Group('instance')]
final class ProbeTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    private string $scratch;

    private string $project;

    public static function tearDownAfterClass(): void
    {
        new Filesystem()->remove(self::sharedProject());
    }

    // One boot holds the script, network and failing-child cases, because each namespaced boot costs seconds.
    public function testAFailingScriptReachesTheInstanceButNotTheNetworkAndItsChildIsKilled(): void
    {
        $this->skipWhenNetworkNamespacesAreRefused();
        $child = $this->childMarker();
        $this->probe('Fail.php', $this->spawningEscapedChild($child).<<<'PHP_WRAP'
            echo "code=", explode(" ", get_headers(getenv("INTAKT_INSTANCE_URL")."/no-such-page")[0])[1], "\n";
            $socket = @fsockopen("192.0.2.1", 80, $error, $message, 3);
            echo $socket ? "reached" : "unreachable: ".$message, "\n";
            exit($socket ? 0 : 7);
            PHP_WRAP);

        $run = $this->runProbe('tests/Probe/Fail.php');

        self::assertStringContainsString('code=404', $run->getOutput(), $this->transcriptOf($run));
        // With a route out the documentation address would only time out; no route at all is what the namespace gives.
        self::assertStringContainsString('unreachable: Network is unreachable', $run->getOutput());
        self::assertStringContainsString('exit code: 7', $run->getOutput());
        self::assertSame(7, $run->getExitCode());
        self::assertFileExists($child, 'the child never confirmed that it was running');
        self::assertSame([], $this->processesNaming($child));
    }

    public function testAPhpunitTestRunsAgainstTheInstance(): void
    {
        $this->skipWhenNetworkNamespacesAreRefused();
        $this->probe('AnswerTest.php', <<<'PHP_WRAP'
        <?php

        use PHPUnit\Framework\TestCase;

        final class AnswerTest extends TestCase
        {
            public function testTheInstanceAnswers(): void
            {
                self::assertStringContainsString('404', get_headers(getenv('INTAKT_INSTANCE_URL').'/no-such-page')[0]);
            }
        }
        PHP_WRAP);

        $run = $this->runProbe('tests/Probe/AnswerTest.php');

        self::assertStringContainsString('OK (1 test, 1 assertion)', $run->getOutput(), $this->transcriptOf($run));
        self::assertSame(0, $run->getExitCode());
    }

    public function testAProbeRunsAgainstTheBuiltInServerWhenFrankenphpIsNotOnThePath(): void
    {
        $this->skipWhenNetworkNamespacesAreRefused();
        $this->probe('Answer.php', '<?php echo get_headers(getenv("INTAKT_INSTANCE_URL")."/no-such-page")[0], "\n";');
        $path = array_filter(explode(':', (string) getenv('PATH')), static fn (string $directory): bool => !is_file($directory.'/frankenphp'));

        $run = $this->runProbe('tests/Probe/Answer.php', ['PATH' => implode(':', $path)]);

        self::assertStringContainsString('404', $run->getOutput(), $this->transcriptOf($run));
        self::assertSame(0, $run->getExitCode());
    }

    public function testAProbeNeverRunsWhenTheNamespacesCannotBeCreated(): void
    {
        $this->probe('Ran.php', '<?php echo "probe ran\n";');
        new Filesystem()->dumpFile($this->scratch.'/bin/unshare', "#!/bin/sh\nexit 1\n");
        new Filesystem()->chmod($this->scratch.'/bin/unshare', 0o755);

        $run = $this->runProbe('tests/Probe/Ran.php', ['PATH' => $this->scratch.'/bin:'.getenv('PATH')]);

        self::assertStringNotContainsString('probe ran', $run->getOutput());
        self::assertStringContainsString('the instance did not become ready', $run->getErrorOutput(), $this->transcriptOf($run));
        self::assertSame(1, $run->getExitCode());
    }

    public function testAProbeNamesEveryMissingToolBeforeAnythingStarts(): void
    {
        $this->probe('Ran.php', '<?php echo "probe ran\n";');
        new Filesystem()->mkdir($this->scratch.'/empty');

        $run = $this->runProbe('tests/Probe/Ran.php', ['PATH' => $this->scratch.'/empty']);

        self::assertStringContainsString("unshare not found, see docs/architecture/requirements.md\n", $run->getErrorOutput());
        self::assertStringContainsString("\nsh not found, see docs/architecture/requirements.md\n", $run->getErrorOutput());
        self::assertStringContainsString("\nip not found, see docs/architecture/requirements.md\n", $run->getErrorOutput());
        self::assertStringNotContainsString('probe ran', $run->getOutput());
        self::assertStringNotContainsString('the instance did not become ready', $run->getErrorOutput());
        self::assertSame(2, $run->getExitCode());
    }

    public function testAToolUnderARelativePathEntryIsFoundInTheProjectRootWhereverTheProbeIsCalledFrom(): void
    {
        $this->probe('Ran.php', '<?php echo "probe ran\n";');
        $this->failingTools($this->project.'/tools');

        $run = $this->runProbeFromTheScratchFolder('tests/Probe/Ran.php', ['PATH' => 'tools']);

        self::assertStringNotContainsString('not found', $run->getErrorOutput(), $this->transcriptOf($run));
        self::assertStringContainsString('the instance did not become ready', $run->getErrorOutput());
    }

    public function testAToolInTheProjectRootIsFoundThroughAnEmptyPathEntry(): void
    {
        $this->probe('Ran.php', '<?php echo "probe ran\n";');
        $this->failingTools($this->project);

        $run = $this->runProbeFromTheScratchFolder('tests/Probe/Ran.php', ['PATH' => ':']);

        self::assertStringNotContainsString('not found', $run->getErrorOutput(), $this->transcriptOf($run));
        self::assertStringContainsString('the instance did not become ready', $run->getErrorOutput());
    }

    public function testAToolOnlyUnderARelativePathEntryOfTheCallersFolderIsNamed(): void
    {
        $this->probe('Ran.php', '<?php echo "probe ran\n";');
        $this->failingTools($this->scratch.'/tools');

        $run = $this->runProbeFromTheScratchFolder('tests/Probe/Ran.php', ['PATH' => 'tools']);

        self::assertStringContainsString('unshare not found, see docs/architecture/requirements.md', $run->getErrorOutput());
        self::assertSame(2, $run->getExitCode());
    }

    public function testWithoutAPathVariableAToolInTheDefaultSearchPathIsFound(): void
    {
        $this->probe('Ran.php', '<?php echo "probe ran\n";');

        $run = $this->runProbe('tests/Probe/Ran.php', ['PATH' => false]);

        self::assertStringNotContainsString('sh not found', $run->getErrorOutput(), $this->transcriptOf($run));
    }

    public function testAnEmptyPathVariableSearchesOnlyTheProjectRoot(): void
    {
        $this->probe('Ran.php', '<?php echo "probe ran\n";');

        $run = $this->runProbe('tests/Probe/Ran.php', ['PATH' => '']);

        self::assertStringContainsString("\nsh not found, see docs/architecture/requirements.md\n", $run->getErrorOutput(), $this->transcriptOf($run));
        self::assertSame(2, $run->getExitCode());
    }

    public function testAPathOutsideTheProbeFolderIsRefused(): void
    {
        $run = $this->runProbe('composer.json');

        self::assertSame(2, $run->getExitCode());
        self::assertStringContainsString('usage:', $run->getErrorOutput());
    }

    public function testNothingIsLeftBehindAfterAProbeThatEndsWithZeroAndLeavesAChild(): void
    {
        $this->skipWhenNetworkNamespacesAreRefused();
        $child = $this->childMarker();
        $this->probe('Leave.php', $this->spawningEscapedChild($child).'echo "left a child\n";');

        $run = $this->runProbe('tests/Probe/Leave.php');

        self::assertStringContainsString('left a child', $run->getOutput(), $this->transcriptOf($run));
        self::assertSame(0, $run->getExitCode());
        self::assertFileExists($child, 'the child never confirmed that it was running');
        self::assertSame([], $this->processesNaming($child));
        self::assertSame([], $this->entriesOf($this->scratch.'/tmp'));
        self::assertSame([], $this->processesNaming($this->project.'/public'));
    }

    public function testAProbeThatOverrunsItsTimeIsKilledWithItsChildrenAndExitCode124(): void
    {
        $this->skipWhenNetworkNamespacesAreRefused();
        $child = $this->childMarker();
        $this->probe('Hang.php', $this->spawningEscapedChild($child).'sleep(30);');

        $run = $this->runProbe('tests/Probe/Hang.php', ['INTAKT_INSTANCE_COMMAND_SECONDS' => '2']);

        self::assertStringContainsString('exit code: 124', $run->getOutput(), $this->transcriptOf($run));
        self::assertSame(124, $run->getExitCode());
        self::assertFileExists($child, 'the child never confirmed that it was running');
        self::assertSame([], $this->processesNaming($child));
        self::assertSame([], $this->processesNaming($this->project.'/public'));
    }

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().'/intakt-probe-test-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->scratch.'/tmp');
        $this->project = $this->copyOfTheProject();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->scratch);
    }

    // Same rule as the gate tests: a host without unprivileged namespaces cannot run a probe, which the refusal test covers.
    private function skipWhenNetworkNamespacesAreRefused(): void
    {
        $namespaces = new Process(['unshare', '--net', '--map-root-user', '--pid', '--fork', '--kill-child', 'ip', 'link', 'set', 'lo', 'up']);

        if (0 !== $namespaces->run()) {
            self::markTestSkipped('This host refuses unprivileged network namespaces: '.trim($namespaces->getErrorOutput()));
        }
    }

    // One path is both the child's argument and the file it creates, so the process list and the file system name the same child.
    private function childMarker(): string
    {
        return $this->scratch.'/child-'.bin2hex(random_bytes(6));
    }

    // The child (own session, stderr kept open) survives a group kill and a closed pipe, and the probe waits for its marker file, so a child that never started cannot pass as a killed one.
    private function spawningEscapedChild(string $marker): string
    {
        $start = 'setsid '.PHP_BINARY.' -r \'touch($argv[1]); sleep(300);\' '.$marker.' 1>&2 &';

        return '<?php system('.var_export($start, true).'); while (!is_file('.var_export($marker, true).')) { usleep(10_000); }';
    }

    /**
     * @return list<string>
     */
    private function entriesOf(string $directory): array
    {
        $found = scandir($directory);

        return array_values(array_diff(false === $found ? [] : $found, ['.', '..']));
    }

    private function probe(string $name, string $code): void
    {
        new Filesystem()->dumpFile($this->project.'/tests/Probe/'.$name, $code);
    }

    /**
     * @param array<string, string|false> $environment
     */
    private function runProbe(string $file, array $environment = []): Process
    {
        $process = new Process([PHP_BINARY, $this->project.'/bin/probe', $file], $this->project, ['TMPDIR' => $this->scratch.'/tmp', 'APP_ENV' => 'test', ...$environment]);
        $process->run();

        return $process;
    }

    private function transcriptOf(Process $run): string
    {
        return 'exit '.$run->getExitCode()."\nstdout:\n".$run->getOutput()."\nstderr:\n".$run->getErrorOutput();
    }

    // Each tool fails at once, so a run that finds them stops before the probe instead of reaching the network.
    private function failingTools(string $folder): void
    {
        foreach (['unshare', 'sh', 'ip'] as $tool) {
            new Filesystem()->dumpFile($folder.'/'.$tool, "#!/bin/sh\nexit 1\n");
            new Filesystem()->chmod($folder.'/'.$tool, 0o755);
        }
    }

    /**
     * @param array<string, string> $environment
     */
    private function runProbeFromTheScratchFolder(string $file, array $environment): Process
    {
        $process = new Process([PHP_BINARY, $this->project.'/bin/probe', $file], $this->scratch, ['TMPDIR' => $this->scratch.'/tmp', 'APP_ENV' => 'test', ...$environment]);
        $process->run();

        return $process;
    }

    /**
     * @return list<string>
     */
    private function processesNaming(string $argument): array
    {
        $found = [];

        $files = glob('/proc/[0-9]*/cmdline');

        foreach (false === $files ? [] : $files as $file) {
            $arguments = explode("\0", (string) @file_get_contents($file));

            if (in_array($argument, $arguments, true)) {
                $found[] = $file;
            }
        }

        return $found;
    }

    private function copyOfTheProject(): string
    {
        $project = self::sharedProject();
        new Filesystem()->mkdir($project);
        ProjectCopy::refresh(self::ROOT, $project, 'bin', 'src', 'config', 'public', 'composer.json', '.env', '.env.test');
        new Filesystem()->copy(self::ROOT.'/tests/bootstrap.php', $project.'/tests/bootstrap.php');
        new Filesystem()->remove($project.'/config/reference.php');

        return $project;
    }

    // Named by the process, because the copy outlives a test while two runs of this class side by side must not share it.
    private static function sharedProject(): string
    {
        return sys_get_temp_dir().'/intakt-probe-test-project-'.getmypid();
    }
}
