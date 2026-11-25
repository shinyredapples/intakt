<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ChildCommand;
use Intakt\Gates\ProcessGroup;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\RunningChild;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(ProcessGroup::class)]
#[CoversClass(ProcessRunner::class)]
#[UsesClass(ChildCommand::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(RunningChild::class)]
final class GateChildLauncherStopTest extends TestCase
{
    // Because the stand-in holds the launcher before its session exists until the test lets it go, the stop lands in that window by construction.
    private const string HOLDING_SETSID = <<<'SH'
        #!/bin/sh
        touch "$INTAKT_TEST_REACHED"
        while [ ! -e "$INTAKT_TEST_GO" ]; do sleep 0.01; done
        if [ -n "$INTAKT_TEST_IGNORE_TERM" ]; then trap '' TERM; fi
        exec "$INTAKT_TEST_REAL_SETSID" "$@"
        SH;

    private string $folder;

    private bool $ignoringTerm = false;

    public function testALauncherStoppedBeforeItsSetsidDoesNotRunItsGateAfterTheStop(): void
    {
        $child = $this->heldBeforeItsSetsid(['touch', $this->folder.'/marker']);

        $child->group->stop();
        touch($this->folder.'/go');
        self::pollFor($child, 1_000_000);

        self::assertFileDoesNotExist($this->folder.'/marker');
    }

    public function testALauncherKilledBeforeItsSetsidDoesNotRunItsGateAfterTheKill(): void
    {
        $child = $this->heldBeforeItsSetsid(['touch', $this->folder.'/marker']);

        $child->group->kill();
        touch($this->folder.'/go');
        self::pollFor($child, 1_000_000);

        self::assertFileDoesNotExist($this->folder.'/marker');
    }

    public function testALauncherThatCreatesItsSessionAndStartsAProgramDuringTheStopHasThatProgramStopped(): void
    {
        // Because an ignored TERM survives exec, the program starts after the group was asked to stop and ends only by the group's KILL.
        $this->ignoringTerm = true;
        $child = $this->heldBeforeItsSetsid([PHP_BINARY, '-r', 'touch('.var_export($this->folder.'/started', true).'); usleep(3000000); touch('.var_export($this->folder.'/marker', true).');']);
        $release = new Process(['sh', '-c', 'sleep 0.3; touch "$0"', $this->folder.'/go']);
        $release->start();

        $child->group->stop();
        $release->wait();
        self::pollFor($child, 4_000_000);

        self::assertFileExists($this->folder.'/started');
        self::assertFileDoesNotExist($this->folder.'/marker');
    }

    public function testAProcessThatReusesTheNumberOfAReapedLauncherIsNotSignaled(): void
    {
        $launcher = new Process(['true']);
        $launcher->run();
        $bystander = new Process(['sleep', '30']);
        $bystander->start();
        $group = new ProcessGroup((int) $bystander->getPid(), $launcher);

        $group->askToStop();
        $group->stop();
        $group->kill();
        $stillRunning = $bystander->isRunning();
        $bystander->stop(0);

        self::assertTrue($stillRunning);
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-launcher-stop-'.bin2hex(random_bytes(6));
        mkdir($this->folder.'/tools', 0o700, true);
        file_put_contents($this->folder.'/tools/setsid', self::HOLDING_SETSID);
        chmod($this->folder.'/tools/setsid', 0o700);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    /**
     * @param list<string> $gate
     */
    private function heldBeforeItsSetsid(array $gate): RunningChild
    {
        $environment = [
            'INTAKT_TEST_REACHED' => $this->folder.'/reached',
            'INTAKT_TEST_GO' => $this->folder.'/go',
            'INTAKT_TEST_REAL_SETSID' => trim((string) shell_exec('command -v setsid')),
            'INTAKT_TEST_IGNORE_TERM' => $this->ignoringTerm ? '1' : '',
        ];
        $path = (string) getenv('PATH');
        // Because PHP's proc_open() searches the launcher on the PATH of bin/check itself, not on the one it hands the child.
        putenv('PATH='.$this->folder.'/tools:'.$path);

        try {
            $child = new ProcessRunner()->startToItsEndWithToolsFrom($this->folder, new ChildCommand($gate, $this->folder, $environment));
        } finally {
            putenv('PATH='.$path);
        }

        self::assertInstanceOf(RunningChild::class, $child);

        for ($tries = 0; !is_file($this->folder.'/reached') && $tries < 500; ++$tries) {
            usleep(10_000);
        }

        self::assertFileExists($this->folder.'/reached');

        return $child;
    }

    // Because Symfony Process hands the launcher its release line only while the process is polled, the test polls as bin/check does instead of only sleeping.
    private static function pollFor(RunningChild $child, int $microseconds): void
    {
        for ($waited = 0; $waited < $microseconds; $waited += 10_000) {
            $child->process->isRunning();
            usleep(10_000);
        }
    }
}
