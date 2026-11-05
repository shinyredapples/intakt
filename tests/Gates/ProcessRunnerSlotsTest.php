<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ProcessRunner::class)]
#[UsesClass(ProcessResult::class)]
final class ProcessRunnerSlotsTest extends SandboxTestCase
{
    public function testWithOneSlotTheNextCommandStartsOnlyAfterThePreviousOneEnded(): void
    {
        $sandbox = $this->emptySandbox();

        $results = new ProcessRunner()->runAtMost(1, [
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

        $results = new ProcessRunner()->runAtMost(2, [
            'first' => [['sh', '-c', $waitsForTheOther], []],
            'second' => [['sh', '-c', 'touch second.started'], []],
        ], $sandbox->root);

        self::assertTrue($results['first']->succeeded());
        self::assertTrue($results['second']->succeeded());
    }

    public function testACommandThatCannotStartIsReportedWhileTheOthersRun(): void
    {
        $results = new ProcessRunner()->runAtMost(1, [
            'missing' => [['/nonexistent/program'], []],
            'present' => [['sh', '-c', 'echo here'], []],
        ], $this->emptySandbox()->root);

        self::assertFalse($results['missing']->succeeded());
        self::assertSame("here\n", $results['present']->output);
    }
}
