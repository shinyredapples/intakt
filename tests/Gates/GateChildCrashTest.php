<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateReport;
use Intakt\Gates\GateResult;
use Intakt\Gates\ProcessGroup;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\StopSignalForwarding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessRunner::class)]
#[UsesClass(GateReport::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(ProcessGroup::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(StopSignalForwarding::class)]
final class GateChildCrashTest extends TestCase
{
    public function testAGateWhoseChildIsKilledBySignalFailsWithWhatTheChildPrintedBeforeIt(): void
    {
        $child = new ProcessRunner()->runToItsEnd([PHP_BINARY, '-r', 'echo "crash marker"; fflush(STDOUT); fwrite(STDERR, "crash error marker"); posix_kill(getmypid(), SIGKILL);'], sys_get_temp_dir(), 10);

        $report = GateReport::fromChild('cs', $child);

        self::assertNotSame(0, $child->exitCode);
        self::assertFalse($report->isPassing());
        self::assertStringContainsString('crash marker', $report->line);
        self::assertStringContainsString('crash error marker', $report->line);
        self::assertStringContainsString('signal 9', $report->line);
    }

    public function testAGateChildThatCannotStartFailsWithoutOutput(): void
    {
        $child = new ProcessRunner()->runToItsEnd([sys_get_temp_dir().'/no-such-gate-program'], sys_get_temp_dir(), 10);

        self::assertNotSame(0, $child->exitCode);
        self::assertSame('', $child->output);
    }
}
