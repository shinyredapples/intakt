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
final class ProbeTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    private string $scratch;

    private string $project;

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

    public function testAScriptRunsAgainstTheInstanceAndItsOutputAndExitCodeAreReported(): void
    {
        $this->skipWhenNetworkNamespacesAreRefused();
        $this->probe('Answer.php', '<?php echo "code=", explode(" ", get_headers(getenv("INTAKT_INSTANCE_URL"))[0])[1], "\n"; exit(7);');

        $run = $this->runProbe('tests/Probe/Answer.php');

        self::assertStringContainsString('code=404', $run->getOutput(), $this->transcriptOf($run));
        self::assertStringContainsString('exit code: 7', $run->getOutput());
        self::assertSame(7, $run->getExitCode());
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
                self::assertStringContainsString('404', get_headers(getenv('INTAKT_INSTANCE_URL'))[0]);
            }
        }
        PHP_WRAP);

        $run = $this->runProbe('tests/Probe/AnswerTest.php');

        self::assertStringContainsString('OK (1 test, 1 assertion)', $run->getOutput(), $this->transcriptOf($run));
        self::assertSame(0, $run->getExitCode());
    }

    public function testAProbeThatReachesForTheNetworkFails(): void
    {
        $this->skipWhenNetworkNamespacesAreRefused();
        $this->probe('Reach.php', '<?php $socket = @fsockopen("192.0.2.1", 80, $code, $message, 3); echo $socket ? "reached" : "unreachable: ".$message, "\n"; exit($socket ? 0 : 3);');

        $run = $this->runProbe('tests/Probe/Reach.php');

        // With a route out the documentation address would only time out; no route at all is what the namespace gives.
        self::assertStringContainsString('unreachable: Network is unreachable', $run->getOutput(), $this->transcriptOf($run));
        self::assertSame(3, $run->getExitCode());
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

    public function testAPathOutsideTheProbeFolderIsRefused(): void
    {
        $run = $this->runProbe('composer.json');

        self::assertSame(2, $run->getExitCode());
        self::assertStringContainsString('usage:', $run->getErrorOutput());
    }

    public function testNothingIsLeftBehindAfterAProbe(): void
    {
        $this->skipWhenNetworkNamespacesAreRefused();
        $this->probe('Quiet.php', '<?php echo "ok\n";');

        $run = $this->runProbe('tests/Probe/Quiet.php');

        self::assertSame(0, $run->getExitCode(), $this->transcriptOf($run));
        self::assertSame([], $this->entriesOf($this->scratch.'/tmp'));
        self::assertSame([], $this->processesNaming($this->project.'/public'));
    }

    public function testAChildThatAProbeLeavesRunningIsKilledWithTheInstance(): void
    {
        $this->skipWhenNetworkNamespacesAreRefused();
        $marker = 'intakt-probe-child-'.bin2hex(random_bytes(6));
        $this->probe('Leave.php', $this->spawningEscapedChild($marker).'echo "left a child\n";');

        $run = $this->runProbe('tests/Probe/Leave.php');

        self::assertStringContainsString('left a child', $run->getOutput(), $this->transcriptOf($run));
        self::assertSame(0, $run->getExitCode());
        self::assertSame([], $this->processesNaming($marker));
    }

    public function testAProbeThatOverrunsItsTimeIsKilledWithItsChildrenAndExitCode124(): void
    {
        $this->skipWhenNetworkNamespacesAreRefused();
        $marker = 'intakt-probe-child-'.bin2hex(random_bytes(6));
        $this->probe('Hang.php', $this->spawningEscapedChild($marker).'sleep(30);');

        $run = $this->runProbe('tests/Probe/Hang.php', ['INTAKT_INSTANCE_COMMAND_SECONDS' => '2']);

        self::assertStringContainsString('exit code: 124', $run->getOutput(), $this->transcriptOf($run));
        self::assertSame(124, $run->getExitCode());
        self::assertSame([], $this->processesNaming($marker));
        self::assertSame([], $this->processesNaming($this->project.'/public'));
    }

    // Same rule as the gate tests: a host without unprivileged namespaces cannot run a probe, which the refusal test covers.
    private function skipWhenNetworkNamespacesAreRefused(): void
    {
        $namespaces = new Process(['unshare', '--net', '--map-root-user', '--pid', '--fork', '--kill-child', 'ip', 'link', 'set', 'lo', 'up']);

        if (0 !== $namespaces->run()) {
            self::markTestSkipped('This host refuses unprivileged network namespaces: '.trim($namespaces->getErrorOutput()));
        }
    }

    // The child starts its own session and keeps the caller's stderr open, so neither a process group kill nor a closed pipe would end it.
    private function spawningEscapedChild(string $marker): string
    {
        return '<?php system("setsid ".PHP_BINARY." -r \'sleep(300);\' '.$marker.' 1>&2 &");';
    }

    private function transcriptOf(Process $run): string
    {
        return 'exit '.$run->getExitCode()."\nstdout:\n".$run->getOutput()."\nstderr:\n".$run->getErrorOutput();
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
     * @param array<string, string> $environment
     */
    private function runProbe(string $file, array $environment = []): Process
    {
        $process = new Process([PHP_BINARY, $this->project.'/bin/probe', $file], $this->project, ['TMPDIR' => $this->scratch.'/tmp', 'APP_ENV' => 'test', ...$environment]);
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
        $project = $this->scratch.'/project';
        new Filesystem()->mkdir($project.'/tests');

        // Hard links keep the vendor copy cheap; across file systems they fail partway, and cp -a would nest into the partial folder.
        $copy = new Process(['sh', '-c', 'cp -al "$0"/vendor "$1"/vendor 2>/dev/null || { rm -rf "$1"/vendor && cp -a "$0"/vendor "$1"/vendor; }', self::ROOT, $project]);
        $copy->mustRun();

        foreach (['bin', 'src', 'config', 'public'] as $directory) {
            new Filesystem()->mirror(self::ROOT.'/'.$directory, $project.'/'.$directory);
        }

        foreach (['composer.json', '.env', '.env.test'] as $file) {
            new Filesystem()->copy(self::ROOT.'/'.$file, $project.'/'.$file);
        }

        new Filesystem()->copy(self::ROOT.'/tests/bootstrap.php', $project.'/tests/bootstrap.php');
        new Filesystem()->remove($project.'/config/reference.php');

        return $project;
    }
}
