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
        $this->probe('Answer.php', '<?php echo "code=", explode(" ", get_headers(getenv("INTAKT_INSTANCE_URL"))[0])[1], "\n"; exit(7);');

        $run = $this->runProbe('tests/Probe/Answer.php');

        self::assertStringContainsString('code=404', $run->getOutput());
        self::assertStringContainsString('exit code: 7', $run->getOutput());
        self::assertSame(7, $run->getExitCode());
    }

    public function testAPhpunitTestRunsAgainstTheInstance(): void
    {
        $this->probe('AnswerTest.php', <<<'PHP'
            <?php

            use PHPUnit\Framework\TestCase;

            final class AnswerTest extends TestCase
            {
                public function testTheInstanceAnswers(): void
                {
                    self::assertStringContainsString('404', get_headers(getenv('INTAKT_INSTANCE_URL'))[0]);
                }
            }
            PHP);

        $run = $this->runProbe('tests/Probe/AnswerTest.php');

        self::assertStringContainsString('Tests: 1, Assertions: 1', $run->getOutput());
        self::assertSame(0, $run->getExitCode());
    }

    public function testAProbeThatReachesForTheNetworkFails(): void
    {
        $this->probe('Reach.php', '<?php $socket = @fsockopen("192.0.2.1", 80, $code, $message, 3); echo $socket ? "reached" : "unreachable: ".$message, "\n"; exit($socket ? 0 : 3);');

        $run = $this->runProbe('tests/Probe/Reach.php');

        self::assertStringContainsString('unreachable', $run->getOutput());
        self::assertSame(3, $run->getExitCode());
    }

    public function testAPathOutsideTheProbeFolderIsRefused(): void
    {
        $run = $this->runProbe('composer.json');

        self::assertSame(2, $run->getExitCode());
        self::assertStringContainsString('usage:', $run->getErrorOutput());
    }

    public function testNothingIsLeftBehindAfterAProbe(): void
    {
        $this->probe('Quiet.php', '<?php echo "ok\n";');

        $this->runProbe('tests/Probe/Quiet.php');

        self::assertSame([], array_values(array_diff(scandir($this->scratch.'/tmp') ?: [], ['.', '..'])));
        self::assertSame([], $this->serversOf($this->project));
    }

    private function probe(string $name, string $code): void
    {
        new Filesystem()->dumpFile($this->project.'/tests/Probe/'.$name, $code);
    }

    private function runProbe(string $file): Process
    {
        $process = new Process([PHP_BINARY, $this->project.'/bin/probe', $file], $this->project, ['TMPDIR' => $this->scratch.'/tmp', 'APP_ENV' => 'test']);
        $process->run();

        return $process;
    }

    /**
     * @return list<string>
     */
    private function serversOf(string $project): array
    {
        $found = [];

        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $arguments = explode("\0", (string) @file_get_contents($file));

            if (in_array($project.'/public', $arguments, true)) {
                $found[] = $file;
            }
        }

        return $found;
    }

    private function copyOfTheProject(): string
    {
        $project = $this->scratch.'/project';
        new Filesystem()->mkdir($project.'/tests');

        // Hard links keep the vendor copy cheap, and a real copy keeps its autoloader pointing at the copy of src.
        $copy = new Process(['sh', '-c', 'cp -al "$0"/vendor "$1"/vendor 2>/dev/null || cp -a "$0"/vendor "$1"/vendor', self::ROOT, $project]);
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
