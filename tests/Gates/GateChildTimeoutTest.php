<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateReport;
use Intakt\Gates\GateResult;
use Intakt\Gates\ProcessGroup;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\StopSignalForwarding;
use Intakt\Gates\TimeLimit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessRunner::class)]
#[UsesClass(GateReport::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(ProcessGroup::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(StopSignalForwarding::class)]
#[UsesClass(TimeLimit::class)]
final class GateChildTimeoutTest extends TestCase
{
    public function testAGateWhoseChildTimesOutFailsWithWhatTheChildPrintedBeforeIt(): void
    {
        $child = new ProcessRunner()->runToItsEnd([PHP_BINARY, '-r', 'fwrite(STDOUT, "gate progress marker\n"); fwrite(STDERR, "gate error marker\n"); sleep(30);'], sys_get_temp_dir(), 1);

        $report = GateReport::fromChild('cs', $child);

        self::assertSame(ProcessRunner::TIMED_OUT, $child->exitCode);
        self::assertFalse($report->isPassing());
        self::assertStringContainsString('timed out after 1 seconds', $report->line);
        self::assertStringContainsString('gate progress marker', $report->line);
        self::assertStringContainsString('gate error marker', $report->line);
    }

    public function testAGateChildPastAFractionalTimeFailsWithItsLimitAndWhatItPrinted(): void
    {
        $child = new ProcessRunner()->runToItsEnd([PHP_BINARY, '-r', 'fwrite(STDOUT, "gate progress marker\n"); sleep(30);'], sys_get_temp_dir(), 0.3);

        self::assertSame(ProcessRunner::TIMED_OUT, $child->exitCode);
        self::assertSame("gate progress marker\n", $child->output);
        self::assertStringEndsWith('timed out after 0.3 seconds', $child->errors);
    }

    public function testAGateChildWithALimitThatIsNotFiniteInNanosecondsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcessRunner()->runToItsEnd([PHP_BINARY, '-r', 'echo "ran";'], sys_get_temp_dir(), PHP_FLOAT_MAX);
    }

    public function testAGateChildWithinItsTimeKeepsItsOutputAndExitCode(): void
    {
        $child = new ProcessRunner()->runToItsEnd([PHP_BINARY, '-r', 'echo "out"; fwrite(STDERR, "err"); exit(1);'], sys_get_temp_dir(), 10);

        self::assertSame(1, $child->exitCode);
        self::assertSame('out', $child->output);
        self::assertSame('err', $child->errors);
    }
}
