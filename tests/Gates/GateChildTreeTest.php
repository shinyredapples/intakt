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
    // A safety net only: every wait below ends on a file the child writes or on its process group being gone.
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
        $child = new ProcessRunner()->runToItsEnd($this->gateRunningWithin($this->descendant()), sys_get_temp_dir(), 1);

        self::assertSame(ProcessRunner::TIMED_OUT, $child->exitCode);
        self::assertStringContainsString('started', $child->output);
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
        foreach ([$this->marker, $this->group, $this->group.'-writing', $this->marker.'-ready'] as $file) {
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

    // The descendant writes its process group first, renamed into place so it is never read half written, then its marker only if it outlives the stop.
    private function descendant(string $then = ''): string
    {
        $writing = var_export($this->group.'-writing', true);

        return 'file_put_contents('.$writing.', (string) posix_getpgrp()); rename('.$writing.', '.var_export($this->group, true).'); '.$then.' usleep(1500000); file_put_contents('.var_export($this->marker, true).', "still running");';
    }

    /**
     * @return list<string>
     */
    private function gateRunningWithin(string $program): array
    {
        $gate = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).'; echo "started"; fflush(STDOUT); new '.ProcessRunner::class.'()->runWithin('.var_export([PHP_BINARY, '-r', $program], true).', sys_get_temp_dir(), 30);';

        return [PHP_BINARY, '-r', $gate];
    }

    // Once no process of the descendant's group is left, none can write the marker later, so its absence now proves it is never written.
    private function assertTheDescendantIsGoneWithoutWritingItsMarker(): void
    {
        $group = $this->descendantGroup();

        self::assertFalse(new ProcessGroup($group)->outlives($this->deadline()), sprintf('Process group %d still runs %d seconds after its gate child ended.', $group, self::SECONDS_TO_WAIT));
        self::assertFileDoesNotExist($this->marker);
    }

    private function descendantGroup(): int
    {
        $deadline = $this->deadline();

        while (!is_file($this->group) && hrtime(true) / 1_000_000_000 < $deadline) {
            usleep(10_000);
        }

        self::assertFileExists($this->group, 'The descendant never started.');

        return (int) file_get_contents($this->group);
    }

    private function deadline(): float
    {
        return hrtime(true) / 1_000_000_000 + self::SECONDS_TO_WAIT;
    }
}
