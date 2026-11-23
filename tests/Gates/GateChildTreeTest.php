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
    private string $marker;

    public function testAProgramThatATimedOutGateChildStartedIsStoppedWithIt(): void
    {
        $child = new ProcessRunner()->runToItsEnd($this->gateLeavingADescendant('sleep(10);'), sys_get_temp_dir(), 1);
        usleep(2_500_000);

        self::assertSame(ProcessRunner::TIMED_OUT, $child->exitCode);
        self::assertStringContainsString('started', $child->output);
        self::assertFileDoesNotExist($this->marker);
    }

    public function testAProgramThatAFinishedGateChildLeftBehindIsStoppedWithIt(): void
    {
        $child = new ProcessRunner()->runToItsEnd($this->gateLeavingADescendant('exit(0);'), sys_get_temp_dir(), 10);
        usleep(2_500_000);

        self::assertSame(0, $child->exitCode);
        self::assertSame('started', $child->output);
        self::assertFileDoesNotExist($this->marker);
    }

    public function testAGateChildAndItsProgramsStopWhenBinCheckIsInterrupted(): void
    {
        $ready = $this->marker.'-ready';

        $status = $this->interruptBinCheckRunning($this->gateLeavingADescendant('touch('.var_export($ready, true).'); sleep(10);'), $ready);

        self::assertTrue($status['signaled']);
        self::assertSame(2, $status['termsig']);
        self::assertFileDoesNotExist($this->marker);
    }

    public function testAContainedRunOfATimedOutGateChildIsStoppedWithIt(): void
    {
        $child = new ProcessRunner()->runToItsEnd($this->gateRunningWithin($this->descendant()), sys_get_temp_dir(), 1);
        usleep(2_500_000);

        self::assertSame(ProcessRunner::TIMED_OUT, $child->exitCode);
        self::assertStringContainsString('started', $child->output);
        self::assertFileDoesNotExist($this->marker);
    }

    public function testAContainedRunOfAGateChildStopsWhenBinCheckIsInterrupted(): void
    {
        $ready = $this->marker.'-ready';

        $status = $this->interruptBinCheckRunning($this->gateRunningWithin($this->descendant('touch('.var_export($ready, true).');')), $ready);

        self::assertTrue($status['signaled']);
        self::assertSame(2, $status['termsig']);
        self::assertFileDoesNotExist($this->marker);
    }

    protected function setUp(): void
    {
        $this->marker = sys_get_temp_dir().'/intakt-gate-descendant-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_file($this->marker)) {
            unlink($this->marker);
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

        usleep(2_000_000);

        return $status;
    }

    /**
     * @return list<string>
     */
    private function gateLeavingADescendant(string $then): array
    {
        $gate = 'proc_open('.var_export([PHP_BINARY, '-r', $this->descendant()], true).', [], $pipes); echo "started"; fflush(STDOUT); '.$then;

        return [PHP_BINARY, '-r', $gate];
    }

    private function descendant(string $first = ''): string
    {
        return $first.' usleep(1500000); file_put_contents('.var_export($this->marker, true).', "still running");';
    }

    /**
     * @return list<string>
     */
    private function gateRunningWithin(string $program): array
    {
        $gate = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).'; echo "started"; fflush(STDOUT); new '.ProcessRunner::class.'()->runWithin('.var_export([PHP_BINARY, '-r', $program], true).', sys_get_temp_dir(), 30);';

        return [PHP_BINARY, '-r', $gate];
    }
}
