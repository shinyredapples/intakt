<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Infrastructure\StartCheck;
use Intakt\Core\Job\Infrastructure\StartCheckFailure;
use Intakt\Core\Job\Infrastructure\StartCheckLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StartCheckLog::class)]
#[UsesClass(StartCheck::class)]
#[UsesClass(StartCheckFailure::class)]
final class StartCheckLogTest extends TestCase
{
    private const string NO_MODE_LINE = 'no mode line in the first 65536 bytes of the log: the check did not finish, or it did not run';

    public function testALogOfPassedChecksEndingInFullModeHoldsNoFailure(): void
    {
        self::assertSame([], $this->failuresOf(
            "intakt-start-check: output: ok\nintakt-start-check: work: ok\nintakt-start-check: input: /input/repository: ok\n"
            ."intakt-start-check: egress: api.example: ok\nintakt-start-check: full mode\n",
        ));
    }

    public function testEachFailedCheckBeforeTheReducedModeLineIsOneFailureWithItsReason(): void
    {
        self::assertEquals(
            [
                new StartCheckFailure(StartCheck::Output, 'cannot write a probe file in /output'),
                new StartCheckFailure(StartCheck::Input, '/input/memory is not readable'),
                new StartCheckFailure(StartCheck::Egress, 'api.example is not reachable through the proxy (curl exit 7)'),
            ],
            $this->failuresOf(
                "intakt-start-check: output: cannot write a probe file in /output\nintakt-start-check: work: ok\n"
                ."intakt-start-check: input: /input/memory is not readable\n"
                ."intakt-start-check: egress: api.example is not reachable through the proxy (curl exit 7)\n"
                ."intakt-start-check: reduced mode: output input egress\n",
            ),
        );
    }

    public function testEverythingAfterTheFirstModeLineIsIgnored(): void
    {
        self::assertEquals(
            [new StartCheckFailure(StartCheck::Work, 'cannot write a probe file in /work')],
            $this->failuresOf(
                "intakt-start-check: work: cannot write a probe file in /work\nintakt-start-check: reduced mode: work\n"
                ."intakt-start-check: full mode\nintakt-start-check: input: /input is missing\n\x01garbage\n",
            ),
        );
    }

    public function testALineThatIsNotTheCheckOwnIsIgnored(): void
    {
        self::assertSame([], $this->failuresOf("sh: warning from the shell\nintakt-start-check: output: ok\nintakt-start-check: full mode\n"));
    }

    public function testAnEmptyLogIsAFailureAndNeverAPass(): void
    {
        self::assertEquals([new StartCheckFailure(StartCheck::Log, self::NO_MODE_LINE)], $this->failuresOf(''));
    }

    public function testALogCutBeforeItsModeLineIsAFailure(): void
    {
        self::assertEquals(
            [new StartCheckFailure(StartCheck::Output, 'cannot write a probe file in /output'), new StartCheckFailure(StartCheck::Log, self::NO_MODE_LINE)],
            $this->failuresOf("intakt-start-check: output: cannot write a probe file in /output\n"),
        );
    }

    public function testAModeLineWithoutItsLineEndIsNotRead(): void
    {
        self::assertEquals([new StartCheckFailure(StartCheck::Log, self::NO_MODE_LINE)], $this->failuresOf('intakt-start-check: full mode'));
    }

    public function testAFullModeLineAfterAFailedCheckKeepsTheFailureAndIsAFailureItself(): void
    {
        self::assertEquals(
            [
                new StartCheckFailure(StartCheck::Input, '/input is missing'),
                new StartCheckFailure(StartCheck::Log, 'the mode line does not name the checks that failed before it'),
            ],
            $this->failuresOf("intakt-start-check: input: /input is missing\nintakt-start-check: full mode\n"),
        );
    }

    public function testAReducedModeLineWithoutAFailedCheckIsAFailure(): void
    {
        self::assertEquals(
            [new StartCheckFailure(StartCheck::Log, 'the mode line does not name the checks that failed before it')],
            $this->failuresOf("intakt-start-check: reduced mode: egress\n"),
        );
    }

    public function testAReducedModeLineNamesEachFailedCheckOnce(): void
    {
        self::assertCount(2, $this->failuresOf(
            "intakt-start-check: input: /a is missing\nintakt-start-check: input: /b is missing\nintakt-start-check: reduced mode: input\n",
        ));
    }

    public function testALineWithBytesOutsidePrintableAsciiIsReportedByItsNumber(): void
    {
        self::assertEquals(
            [new StartCheckFailure(StartCheck::Log, 'line 2 of the log cannot be read')],
            $this->failuresOf("intakt-start-check: output: ok\nintakt-start-check: input: /input/\xC3\xA9: ok\nintakt-start-check: full mode\n"),
        );
    }

    public function testALineOfAnUnknownCheckIsReportedByItsNumber(): void
    {
        self::assertSame([StartCheck::Log, StartCheck::Log], array_map(
            static fn (StartCheckFailure $failure): StartCheck => $failure->check,
            $this->failuresOf("intakt-start-check: log: forged\nintakt-start-check: reduced mode: log\n"),
        ));
    }

    public function testAtMostTwentyFailuresAreReturned(): void
    {
        self::assertCount(StartCheckLog::MAX_FAILURES, $this->failuresOf(str_repeat("intakt-start-check: input: /input is missing\n", 25)."intakt-start-check: reduced mode: input\n"));
    }

    public function testAReasonIsCutTo255Bytes(): void
    {
        $failures = $this->failuresOf('intakt-start-check: input: '.str_repeat('a', 300)."\nintakt-start-check: reduced mode: input\n");

        self::assertSame(str_repeat('a', 255), $failures[0]->reason);
    }

    /**
     * @return list<StartCheckFailure>
     */
    private function failuresOf(string $log): array
    {
        return new StartCheckLog()->failuresIn($log);
    }
}
