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

    private const string SESSION_ON_GO_SETSID = <<<'SH'
        #!/bin/sh
        exec "$INTAKT_TEST_PHP" "$INTAKT_TEST_STAND_IN"
        SH;

    // Because a shell's own TERM trap is lost on exec, the stand-in is one PHP process: its handler exists before its session does, and it starts a separate program in that session only once the first signal has reached it.
    private const string SESSION_ON_GO = <<<'PHP'
        <?php
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): void {
            exec('sh -c '.escapeshellarg('echo $$ > "$INTAKT_TEST_DESCENDANT"; exec sleep 30').' > /dev/null 2>&1 &');
        });
        touch((string) getenv('INTAKT_TEST_REACHED'));
        while (!is_file((string) getenv('INTAKT_TEST_GO'))) {
            usleep(10000);
        }
        posix_setsid();
        while (true) {
            usleep(10000);
        }
        PHP;

    private string $folder;

    private bool $ignoringTerm = false;

    public function testALauncherStoppedBeforeItsSetsidDoesNotRunItsGateAfterTheStop(): void
    {
        $child = $this->heldBeforeItsSetsid(self::HOLDING_SETSID, ['touch', $this->folder.'/marker']);

        $child->group->stop();
        touch($this->folder.'/go');
        $this->pollFor($child, 1_000_000);

        self::assertFileDoesNotExist($this->folder.'/marker');
    }

    public function testALauncherKilledBeforeItsSetsidDoesNotRunItsGateAfterTheKill(): void
    {
        $child = $this->heldBeforeItsSetsid(self::HOLDING_SETSID, ['touch', $this->folder.'/marker']);

        $child->group->kill();
        touch($this->folder.'/go');
        $this->pollFor($child, 1_000_000);

        self::assertFileDoesNotExist($this->folder.'/marker');
    }

    public function testALauncherThatCreatesItsSessionAsTheStopBeginsHasTheProgramItStartsOnTheFirstSignalStopped(): void
    {
        $child = $this->heldBeforeItsSetsid(self::SESSION_ON_GO_SETSID, ['true']);

        $release = $this->releasedInsideTheStop((int) $child->process->getPid(), $child->group->stop(...));
        $descendant = (int) @file_get_contents($this->folder.'/descendant');
        $this->pollFor($child, 100_000);

        self::assertSame(['stop under way' => true, 'outside its session' => true, 'signaled before' => false], $release);
        self::assertGreaterThan(0, $descendant, 'the first signal reached the new session');
        self::assertFalse($this->isLive($descendant), 'the program started after the first signal is stopped');
        self::assertFalse($child->process->isRunning(), 'the launcher is stopped');
    }

    public function testLaunchersStoppedTogetherBeforeTheirSetsidShareOneGrace(): void
    {
        $first = $this->childOutsideAnySession();
        $second = $this->childOutsideAnySession();

        $start = hrtime(true);
        ProcessGroup::stopTogether([ProcessGroup::ofLauncher($first), ProcessGroup::ofLauncher($second)]);
        $seconds = (hrtime(true) - $start) / 1_000_000_000;

        self::assertSame([SIGKILL, SIGKILL], [$this->signalThatEnded($first), $this->signalThatEnded($second)]);
        self::assertLessThan(ProcessGroup::STOP_GRACE_SECONDS + 1, $seconds);
    }

    public function testALauncherThatCreatesItsSessionWhileAnotherHoldsTheStopIsAskedWithinTheSharedGrace(): void
    {
        $other = $this->childOutsideAnySession();
        $child = $this->heldBeforeItsSetsid(self::SESSION_ON_GO_SETSID, ['true']);
        $start = hrtime(true);

        $release = $this->releasedInsideTheStop((int) $child->process->getPid(), static fn () => ProcessGroup::stopTogether([ProcessGroup::ofLauncher($other), $child->group]));
        $seconds = (hrtime(true) - $start) / 1_000_000_000;
        $descendant = (int) @file_get_contents($this->folder.'/descendant');
        $this->signalThatEnded($other);
        $this->pollFor($child, 100_000);

        self::assertSame(['stop under way' => true, 'outside its session' => true, 'signaled before' => false], $release);
        self::assertGreaterThan(0, $descendant, 'the launcher was asked once its session existed');
        self::assertFalse($this->isLive($descendant), 'the program it started is stopped');
        self::assertLessThan(ProcessGroup::STOP_GRACE_SECONDS + 1, $seconds);
    }

    public function testAStopDoesNotHandALauncherHeldInItsSessionTheLineThatReleasesItsGate(): void
    {
        // Because an ignored TERM survives exec, the held launcher outlives the group's TERM, so only a release line the stop itself delivered could start the gate.
        $this->ignoringTerm = true;
        $child = $this->heldBeforeItsSetsid(self::HOLDING_SETSID, ['touch', $this->folder.'/marker']);
        touch($this->folder.'/go');
        $pid = (int) $child->process->getPid();

        for ($tries = 0; posix_getpgid($pid) !== $pid && $tries < 500; ++$tries) {
            usleep(10_000);
        }

        $child->group->stop();
        $this->pollFor($child, 100_000);

        self::assertFileDoesNotExist($this->folder.'/marker');
    }

    public function testANumberThatIsNoLongerBinCheckSChildIsNotSignaled(): void
    {
        $orphan = new Process(['sh', '-c', 'sleep 30 > /dev/null 2>&1 & echo $!']);
        $orphan->mustRun();
        $pid = (int) trim($orphan->getOutput());
        $group = ProcessGroup::ofLauncher($pid);

        $group->askToStop();
        $group->stop();
        $group->kill();
        $stillLive = $this->isLive($pid);
        posix_kill($pid, 9);

        self::assertTrue($stillLive);
    }

    public function testAChildOfBinCheckGivenTheNumberOfAReapedLauncherIsNotSignaled(): void
    {
        $bystander = new Process(['sleep', '30']);
        $bystander->start();
        $group = new ProcessGroup((int) $bystander->getPid(), 'the start time of a reaped launcher');

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
        file_put_contents($this->folder.'/stand-in.php', self::SESSION_ON_GO);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    /**
     * @param list<string> $gate
     */
    private function heldBeforeItsSetsid(string $setsid, array $gate): RunningChild
    {
        file_put_contents($this->folder.'/tools/setsid', $setsid);
        chmod($this->folder.'/tools/setsid', 0o700);
        $environment = [
            'INTAKT_TEST_REACHED' => $this->folder.'/reached',
            'INTAKT_TEST_GO' => $this->folder.'/go',
            'INTAKT_TEST_REAL_SETSID' => trim((string) shell_exec('command -v setsid')),
            'INTAKT_TEST_IGNORE_TERM' => $this->ignoringTerm ? '1' : '',
            'INTAKT_TEST_PHP' => PHP_BINARY,
            'INTAKT_TEST_STAND_IN' => $this->folder.'/stand-in.php',
            'INTAKT_TEST_DESCENDANT' => $this->folder.'/descendant',
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

    // Because an alarm handler runs inside the test's own process, it releases the launcher at a point it can see: whether the stop had returned, whether the launcher was still outside its session and whether any signal had reached it yet. A stop that returns at once leaves the release to come after it.
    /**
     * @param callable(): void $stop
     *
     * @return array{'stop under way': bool, 'outside its session': bool, 'signaled before': bool}|null
     */
    private function releasedInsideTheStop(int $launcher, callable $stop): ?array
    {
        $returned = false;
        $release = null;
        $asynchronous = pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use (&$returned, &$release, $launcher): void {
            $release = ['stop under way' => !$returned, 'outside its session' => posix_getpgid($launcher) !== $launcher, 'signaled before' => is_file($this->folder.'/descendant')];
            touch($this->folder.'/go');
        });

        try {
            pcntl_alarm(1);
            $stop();
            $returned = true;

            for ($waited = 0; null === $release && $waited < 2_000_000; $waited += 10_000) {
                usleep(10_000);
            }
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_async_signals($asynchronous);
        }

        return $release;
    }

    // Because a forked child that only sleeps never creates a session, it stands for a launcher held before its setsid for the whole grace.
    private function childOutsideAnySession(): int
    {
        $pid = pcntl_fork();

        if (0 === $pid) {
            pcntl_exec('/bin/sleep', ['30']);
            posix_kill(posix_getpid(), SIGKILL);
        }

        self::assertGreaterThan(0, $pid);

        return $pid;
    }

    private function signalThatEnded(int $pid): int
    {
        pcntl_waitpid($pid, $status);

        return pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : 0;
    }

    // Because Symfony Process hands the launcher its release line only while the process is polled, the test polls as bin/check does instead of only sleeping.
    private function pollFor(RunningChild $child, int $microseconds): void
    {
        for ($waited = 0; $waited < $microseconds; $waited += 10_000) {
            $child->process->isRunning();
            usleep(10_000);
        }
    }

    private function isLive(int $pid): bool
    {
        $status = (string) @file_get_contents('/proc/'.$pid.'/stat');
        $end = strrpos($status, ')');

        return false !== $end && 'Z' !== substr($status, $end + 2, 1);
    }
}
