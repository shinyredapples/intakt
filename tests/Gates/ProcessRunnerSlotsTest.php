<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\ProcessSlots;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ProcessRunner::class)]
#[CoversClass(ProcessSlots::class)]
#[UsesClass(ProcessResult::class)]
final class ProcessRunnerSlotsTest extends SandboxTestCase
{
    public function testWithOneSlotTheNextCommandStartsOnlyAfterThePreviousOneEnded(): void
    {
        $sandbox = $this->emptySandbox();

        $results = new ProcessRunner()->runAtMost(new ProcessSlots(1), [
            'first' => [['sh', '-c', 'sleep 0.2; touch first.done'], []],
            'second' => [['sh', '-c', 'test -e first.done'], []],
        ], $sandbox->root);

        self::assertTrue($results['first']->succeeded());
        self::assertTrue($results['second']->succeeded());
    }

    public function testWithTwoSlotsTwoCommandsRunAtTheSameTime(): void
    {
        $sandbox = $this->emptySandbox();
        $waitsForTheOther = 'waited=0; while [ ! -e second.started ] && [ "$waited" -lt 100 ]; do sleep 0.1; waited=$((waited + 1)); done; test -e second.started';

        $results = new ProcessRunner()->runAtMost(new ProcessSlots(2), [
            'first' => [['sh', '-c', $waitsForTheOther], []],
            'second' => [['sh', '-c', 'touch second.started'], []],
        ], $sandbox->root);

        self::assertTrue($results['first']->succeeded());
        self::assertTrue($results['second']->succeeded());
    }

    public function testCommandsMarkedOneAtATimeNeverOverlapEvenWithFreeSlots(): void
    {
        $sandbox = $this->emptySandbox();

        $results = new ProcessRunner()->runAtMost(new ProcessSlots(3, ['first', 'second']), [
            'first' => [['sh', '-c', 'touch first.running; sleep 0.3; rm first.running'], []],
            'second' => [['sh', '-c', 'test ! -e first.running'], []],
        ], $sandbox->root);

        self::assertTrue($results['first']->succeeded());
        self::assertTrue($results['second']->succeeded());
    }

    public function testACommandNotMarkedOneAtATimeRunsAlongsideAMarkedOne(): void
    {
        $sandbox = $this->emptySandbox();
        $waitsForTheOther = 'waited=0; while [ ! -e other.started ] && [ "$waited" -lt 100 ]; do sleep 0.1; waited=$((waited + 1)); done; test -e other.started';

        $results = new ProcessRunner()->runAtMost(new ProcessSlots(2, ['marked']), [
            'marked' => [['sh', '-c', $waitsForTheOther], []],
            'other' => [['sh', '-c', 'touch other.started'], []],
        ], $sandbox->root);

        self::assertTrue($results['marked']->succeeded());
        self::assertTrue($results['other']->succeeded());
    }

    public function testACommandThatCannotStartIsReportedWhileTheOthersRun(): void
    {
        $results = new ProcessRunner()->runAtMost(new ProcessSlots(1), [
            'missing' => [['/nonexistent/program'], []],
            'present' => [['sh', '-c', 'echo here'], []],
        ], $this->emptySandbox()->root);

        self::assertFalse($results['missing']->succeeded());
        self::assertSame("here\n", $results['present']->output);
    }

    public function testACommandThatTimesOutKeepsTheOutputItPrintedBefore(): void
    {
        $results = new ProcessRunner(slotSeconds: 1)->runAtMost(new ProcessSlots(1), [
            'slow' => [['sh', '-c', 'echo progress; echo diagnostic before timeout >&2; sleep 5'], []],
        ], $this->emptySandbox()->root);

        self::assertSame(ProcessRunner::TIMED_OUT, $results['slow']->exitCode);
        self::assertSame("progress\n", $results['slow']->output);
        self::assertSame("diagnostic before timeout\nsh timed out after 1 seconds", $results['slow']->errors);
    }
}
