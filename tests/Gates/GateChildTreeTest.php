<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessGroup;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\StopSignalForwarding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessRunner::class)]
#[CoversClass(ProcessGroup::class)]
#[CoversClass(StopSignalForwarding::class)]
#[UsesClass(ProcessResult::class)]
final class GateChildTreeTest extends TestCase
{
    // Long, because it only stops a hang: every wait below ends early on a file the child writes or on its process group being gone.
    private const int SECONDS_TO_WAIT = 5;

    private string $marker;

    private string $group;

    public function testAProgramThatATimedOutGateChildStartedIsStoppedWithIt(): void
    {
        $child = new ProcessRunner()->runToItsEnd($this->gateLeavingADescendant('sleep(10);'), sys_get_temp_dir(), 1);

        self::assertSame(ProcessRunner::TIMED_OUT, $child->exitCode);
        self::assertStringContainsString('started', $child->output);
        $this->assertTheDescendantIsGoneWithoutWritingItsMarker();
    }

    public function testAProgramThatAFinishedGateChildLeftBehindIsStoppedWithIt(): void
    {
        $child = new ProcessRunner()->runToItsEnd($this->gateLeavingADescendant('exit(0);'), sys_get_temp_dir(), 10);

        self::assertSame(0, $child->exitCode);
        self::assertSame('started', $child->output);
        $this->assertTheDescendantIsGoneWithoutWritingItsMarker();
    }

    public function testAGateChildAndItsProgramsStopWhenBinCheckIsInterrupted(): void
    {
        $ready = $this->marker.'-ready';

        $status = $this->interruptBinCheckRunning($this->gateLeavingADescendant('touch('.var_export($ready, true).'); sleep(10);'), $ready);

        self::assertTrue($status['signaled']);
        self::assertSame(2, $status['termsig']);
        $this->assertTheDescendantIsGoneWithoutWritingItsMarker();
    }

    public function testAContainedRunOfATimedOutGateChildIsStoppedWithIt(): void
    {
        $child = new ProcessRunner()->runToItsEnd($this->gateRunningWithinStoppedOnceReady($this->descendant()), sys_get_temp_dir(), 1);

        self::assertSame(ProcessRunner::TIMED_OUT, $child->exitCode);
        self::assertStringContainsString('started', $child->output);
        $this->assertTheDescendantIsGoneWithoutWritingItsMarker();
    }

    public function testAContainedRunReadyOnlyAfterItsGateChildTimedOutIsStoppedWithIt(): void
    {
        $child = new ProcessRunner()->runToItsEnd($this->gateRunningWithinStoppedOnceReady($this->descendant(before: $this->onlyOnceItExists($this->stopReceived()))), sys_get_temp_dir(), 1);

        self::assertSame(ProcessRunner::TIMED_OUT, $child->exitCode);
        self::assertFileExists($this->group, 'The descendant never became ready after its gate child received the stop.');
        $this->assertTheDescendantIsGoneWithoutWritingItsMarker();
    }

    public function testAContainedRunNeverReadyWhileItsGateChildRunsIsStoppedWithIt(): void
    {
        $child = new ProcessRunner()->runToItsEnd($this->gateRunningWithinStoppedOnceReady($this->descendant(before: 'sleep(10); ')), sys_get_temp_dir(), 1);

        self::assertSame(ProcessRunner::TIMED_OUT, $child->exitCode);
        $this->assertTheDescendantIsGoneWithoutWritingItsMarker();
    }

    public function testAContainedRunOfAGateChildStopsWhenBinCheckIsInterrupted(): void
    {
        $ready = $this->marker.'-ready';

        $status = $this->interruptBinCheckRunning($this->gateRunningWithin($this->descendant('touch('.var_export($ready, true).');')), $ready);

        self::assertTrue($status['signaled']);
        self::assertSame(2, $status['termsig']);
        $this->assertTheDescendantIsGoneWithoutWritingItsMarker();
    }

    protected function setUp(): void
    {
        $this->marker = sys_get_temp_dir().'/intakt-gate-descendant-'.bin2hex(random_bytes(6));
        $this->group = $this->marker.'-group';
    }

    protected function tearDown(): void
    {
        foreach ([$this->marker, $this->group, $this->group.'-writing', $this->marker.'-ready', $this->started(), $this->stopReceived()] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param list<string> $gate
     *
     * @return array{running: bool, signaled: bool, termsig: int}
     */
    private function interruptBinCheckRunning(array $gate, string $ready): array
    {
        $check = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).'; new '.ProcessRunner::class.'()->runToItsEnd('.var_export($gate, true).', sys_get_temp_dir(), 30);';
        $parent = proc_open([PHP_BINARY, '-r', $check], [], $pipes);
        self::assertIsResource($parent);

        for ($tries = 0; !is_file($ready) && $tries < 500; ++$tries) {
            usleep(10_000);
        }

        unlink($ready);
        proc_terminate($parent, 2);
        $status = proc_get_status($parent);

        for ($tries = 0; $status['running'] && $tries < 500; ++$tries) {
            usleep(10_000);
            $status = proc_get_status($parent);
        }

        return $status;
    }

    /**
     * @return list<string>
     */
    private function gateLeavingADescendant(string $then): array
    {
        // The gate waits for the descendant's group file, so the descendant surely runs before the gate ends or is stopped.
        $gate = 'proc_open('.var_export([PHP_BINARY, '-r', $this->descendant()], true).', [], $pipes); while (!is_file('.var_export($this->group, true).')) { usleep(1000); } echo "started"; fflush(STDOUT); '.$then;

        return [PHP_BINARY, '-r', $gate];
    }

    // The descendant notes that it started, so a test never passes on one that never ran, then writes its process group, renamed into place so it is never read half written, then its marker only if it outlives the stop.
    private function descendant(string $then = '', string $before = ''): string
    {
        $writing = var_export($this->group.'-writing', true);

        return 'touch('.var_export($this->started(), true).'); '.$before.'file_put_contents('.$writing.', (string) posix_getpgrp()); rename('.$writing.', '.var_export($this->group, true).'); '.$then.' sleep(10); file_put_contents('.var_export($this->marker, true).', "still running");';
    }

    private function started(): string
    {
        return $this->marker.'-started';
    }

    private function stopReceived(): string
    {
        return $this->marker.'-stop-received';
    }

    // Ends without writing the group file when the deadline passes first, so a run ready before the stop fails the test instead of passing on an order it did not force.
    private function onlyOnceItExists(string $file): string
    {
        return '$deadline = hrtime(true) + '.self::SECONDS_TO_WAIT.' * 1000000000; while (!is_file('.var_export($file, true).') && hrtime(true) < $deadline) { usleep(1000); } if (!is_file('.var_export($file, true).')) { exit(1); } ';
    }

    /**
     * @return list<string>
     */
    private function gateRunningWithin(string $program): array
    {
        $gate = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).'; echo "started"; fflush(STDOUT); new '.ProcessRunner::class.'()->runWithin('.var_export([PHP_BINARY, '-r', $program], true).', sys_get_temp_dir(), 30);';

        return [PHP_BINARY, '-r', $gate];
    }

    // Because the runner's timeout starts before the contained run exists, a stop could otherwise land before `runWithin()` forwards it or before the descendant wrote its group. So the gate runs `runWithin()` in a forked process of a session of its own, and passes the timeout's SIGTERM on to it once that process catches SIGTERM (its forwarding is in place) and the descendant's group file exists, or at the latest after half the runner's stop grace, so the gate never gets the SIGKILL that would leave the run behind. SIGTERM is blocked across the fork and unblocked explicitly on both sides, because whether `pcntl_signal()` unblocks it depends on how PHP was built; the forked process first drops a stop that reached it while it was still in the gate's group.
    /**
     * @return list<string>
     */
    private function gateRunningWithinStoppedOnceReady(string $program): array
    {
        $runIt = 'posix_setsid(); pcntl_sigtimedwait([15], $dropped, 0, 1); pcntl_sigprocmask(SIG_UNBLOCK, [15]); new '.ProcessRunner::class.'()->runWithin('.var_export([PHP_BINARY, '-r', $program], true).', sys_get_temp_dir(), 30); exit(0);';
        $forwardingAndDescendantReady = 'is_file('.var_export($this->group, true).') &&1 === preg_match("/^SigCgt:[[:space:]]*[0-9a-f]*([0-9a-f]{4})/m", (string) @file_get_contents("/proc/".$run."/status"), $caught) && 0 !== (hexdec($caught[1]) & 16384)';
        $passOn = 'pcntl_signal(15, static function () use ($run): void { touch('.var_export($this->stopReceived(), true).'); $deadline = hrtime(true) + '.(ProcessGroup::STOP_GRACE_SECONDS * 500_000_000).'; while (!('.$forwardingAndDescendantReady.') && hrtime(true) < $deadline) { usleep(1000); } posix_kill($run, 15); pcntl_waitpid($run, $status); exit(143); });';
        $gate = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).'; pcntl_sigprocmask(SIG_BLOCK, [15]); $run = pcntl_fork(); if (0 === $run) { '.$runIt.' } pcntl_async_signals(true); '.$passOn.' pcntl_sigprocmask(SIG_UNBLOCK, [15]); echo "started"; fflush(STDOUT); while (0 === pcntl_waitpid($run, $status, WNOHANG)) { usleep(10000); }';

        return [PHP_BINARY, '-r', $gate];
    }

    // Once no process of the descendant is left, none can write the marker later, so its absence now proves it is never written.
    private function assertTheDescendantIsGoneWithoutWritingItsMarker(): void
    {
        $group = $this->descendantGroup();

        // Because in a PID namespace the group leader lies outside it, the descendant reads group 0 there, and only its command line finds it.
        if ($group > 0) {
            self::assertFalse(new ProcessGroup($group, launcherStart: '')->outlives($this->deadline()), sprintf('Process group %d still runs %d seconds after its gate child ended.', $group, self::SECONDS_TO_WAIT));
        }

        self::assertFalse($this->aProcessNamingTheMarkerOutlives($this->deadline()), sprintf('A process naming %s still runs %d seconds after its gate child ended.', $this->marker, self::SECONDS_TO_WAIT));
        self::assertFileDoesNotExist($this->marker);
    }

    // Group 0 when the descendant was stopped before it wrote its group, so only the command line check applies then.
    private function descendantGroup(): int
    {
        $deadline = $this->deadline();

        while (!is_file($this->started()) && hrtime(true) / 1_000_000_000 < $deadline) {
            usleep(10_000);
        }

        self::assertFileExists($this->started(), 'The descendant never started.');

        return is_file($this->group) ? (int) file_get_contents($this->group) : 0;
    }

    private function deadline(): float
    {
        return hrtime(true) / 1_000_000_000 + self::SECONDS_TO_WAIT;
    }

    private function aProcessNamingTheMarkerOutlives(float $deadline): bool
    {
        while ($this->aProcessNamesTheMarker() && hrtime(true) / 1_000_000_000 < $deadline) {
            usleep(10_000);
        }

        return $this->aProcessNamesTheMarker();
    }

    // Because a zombie's command line is empty, only a process that can still run matches.
    private function aProcessNamesTheMarker(): bool
    {
        $commandLines = glob('/proc/[0-9]*/cmdline');
        self::assertIsArray($commandLines, 'The process list could not be read.');

        return array_any($commandLines, fn (string $commandLine): bool => str_contains((string) @file_get_contents($commandLine), $this->marker));
    }
}
