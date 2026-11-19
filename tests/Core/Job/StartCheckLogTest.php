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
    private const string MARK = 'mark-of-this-run';
    private const string CHECK = 'intakt-start-check '.self::MARK.': ';
    private const string NO_MODE_LINE = 'no mode line in the first 65536 bytes of the log: the check did not finish, did not run, or its lines were rotated away';

    public function testALogOfPassedChecksEndingInFullModeHoldsNoFailure(): void
    {
        self::assertSame([], $this->failuresOf(
            self::CHECK."output: ok\n".self::CHECK."work: ok\n".self::CHECK."input: /input/repository: ok\n"
            .self::CHECK."egress: api.example: ok\n".self::CHECK."full mode\n",
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
                self::CHECK."output: cannot write a probe file in /output\n".self::CHECK."work: ok\n"
                .self::CHECK."input: /input/memory is not readable\n"
                .self::CHECK."egress: api.example is not reachable through the proxy (curl exit 7)\n"
                .self::CHECK."reduced mode: output input egress\n",
            ),
        );
    }

    public function testEverythingAfterTheFirstModeLineIsIgnored(): void
    {
        self::assertEquals(
            [new StartCheckFailure(StartCheck::Work, 'cannot write a probe file in /work')],
            $this->failuresOf(
                self::CHECK."work: cannot write a probe file in /work\n".self::CHECK."reduced mode: work\n"
                .self::CHECK."full mode\n".self::CHECK."input: /input is missing\n\x01garbage\n",
            ),
        );
    }

    public function testALineThatIsNotTheCheckOwnIsIgnored(): void
    {
        self::assertSame([], $this->failuresOf("sh: warning from the shell\n".self::CHECK."output: ok\n".self::CHECK."full mode\n"));
    }

    public function testLinesWithoutTheRunsMarkDoNotCount(): void
    {
        self::assertEquals(
            [new StartCheckFailure(StartCheck::Input, '/input is missing'), new StartCheckFailure(StartCheck::Log, self::NO_MODE_LINE)],
            $this->failuresOf(self::CHECK."input: /input is missing\nintakt-start-check: full mode\nintakt-start-check other-mark: full mode\n"),
        );
    }

    // Played without the check's lines, because a rotating log driver drops them after a flood and leaves a head that is only the job's.
    public function testALogWhoseHeadIsOnlyTheJobsForgedLinesIsNotAPass(): void
    {
        $afterRotation = "intakt-start-check: output: ok\nintakt-start-check: work: ok\nintakt-start-check: full mode\n".str_repeat("job noise\n", 10);

        self::assertEquals([new StartCheckFailure(StartCheck::Log, self::NO_MODE_LINE)], $this->failuresOf($afterRotation));
    }

    public function testAnEmptyLogIsAFailureAndNeverAPass(): void
    {
        self::assertEquals([new StartCheckFailure(StartCheck::Log, self::NO_MODE_LINE)], $this->failuresOf(''));
    }

    public function testALogCutBeforeItsModeLineIsAFailure(): void
    {
        self::assertEquals(
            [new StartCheckFailure(StartCheck::Output, 'cannot write a probe file in /output'), new StartCheckFailure(StartCheck::Log, self::NO_MODE_LINE)],
            $this->failuresOf(self::CHECK."output: cannot write a probe file in /output\n"),
        );
    }

    public function testAModeLineWithoutItsLineEndIsNotRead(): void
    {
        self::assertEquals([new StartCheckFailure(StartCheck::Log, self::NO_MODE_LINE)], $this->failuresOf(self::CHECK.'full mode'));
    }

    public function testAFullModeLineAfterAFailedCheckKeepsTheFailureAndIsAFailureItself(): void
    {
        self::assertEquals(
            [
                new StartCheckFailure(StartCheck::Input, '/input is missing'),
                new StartCheckFailure(StartCheck::Log, 'the mode line does not name the checks that failed before it'),
            ],
            $this->failuresOf(self::CHECK."input: /input is missing\n".self::CHECK."full mode\n"),
        );
    }

    public function testAReducedModeLineWithoutAFailedCheckIsAFailure(): void
    {
        self::assertEquals(
            [new StartCheckFailure(StartCheck::Log, 'the mode line does not name the checks that failed before it')],
            $this->failuresOf(self::CHECK."reduced mode: egress\n"),
        );
    }

    public function testAReducedModeLineNamesEachFailedCheckOnce(): void
    {
        self::assertCount(2, $this->failuresOf(
            self::CHECK."input: /a is missing\n".self::CHECK."input: /b is missing\n".self::CHECK."reduced mode: input\n",
        ));
    }

    public function testAPassedCheckOfANonAsciiInputPathIsAPass(): void
    {
        self::assertSame([], $this->failuresOf(self::CHECK."output: ok\n".self::CHECK."input: /input/café: ok\n".self::CHECK."full mode\n"));
    }

    public function testAFailedCheckOfANonAsciiInputPathKeepsThePathInItsReason(): void
    {
        self::assertEquals(
            [new StartCheckFailure(StartCheck::Input, '/input/café is missing')],
            $this->failuresOf(self::CHECK."input: /input/café is missing\n".self::CHECK."reduced mode: input\n"),
        );
    }

    public function testALineThatIsNotValidUtf8IsReportedByItsNumber(): void
    {
        self::assertEquals(
            [new StartCheckFailure(StartCheck::Log, 'line 2 of the log cannot be read')],
            $this->failuresOf(self::CHECK."output: ok\n".self::CHECK."input: /input/\xC3: ok\n".self::CHECK."full mode\n"),
        );
    }

    public function testALineWithAControlCharacterIsReportedByItsNumber(): void
    {
        self::assertEquals(
            [new StartCheckFailure(StartCheck::Log, 'line 1 of the log cannot be read')],
            $this->failuresOf(self::CHECK."input: /input/\x1B[2J: ok\n".self::CHECK."full mode\n"),
        );
    }

    public function testALineOfAnUnknownCheckIsReportedByItsNumber(): void
    {
        self::assertSame([StartCheck::Log, StartCheck::Log], array_map(
            static fn (StartCheckFailure $failure): StartCheck => $failure->check,
            $this->failuresOf(self::CHECK."log: forged\n".self::CHECK."reduced mode: log\n"),
        ));
    }

    public function testAtMostTwentyFailuresAreReturned(): void
    {
        self::assertCount(StartCheckLog::MAX_FAILURES, $this->failuresOf(str_repeat(self::CHECK."input: /input is missing\n", 25).self::CHECK."reduced mode: input\n"));
    }

    public function testAReasonIsCutTo255Bytes(): void
    {
        $failures = $this->failuresOf(self::CHECK.'input: '.str_repeat('a', 300)."\n".self::CHECK."reduced mode: input\n");

        self::assertSame(str_repeat('a', 255), $failures[0]->reason);
    }

    public function testAReasonIsNeverCutInsideACharacter(): void
    {
        $failures = $this->failuresOf(self::CHECK.'input: a'.str_repeat('é', 200)."\n".self::CHECK."reduced mode: input\n");

        self::assertSame('a'.str_repeat('é', 127), $failures[0]->reason);
    }

    /**
     * @return list<StartCheckFailure>
     */
    private function failuresOf(string $log): array
    {
        return new StartCheckLog()->failuresIn($log, self::MARK);
    }
}
