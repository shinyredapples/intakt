<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(RecordedProcess::class)]
final class RecordedProcessTest extends TestCase
{
    public function testTheRecordedProcessStillRunsWhileItRuns(): void
    {
        self::assertTrue(RecordedProcess::current()->stillRuns());
    }

    // The number of a worker whose call ended long ago, given since to another process that started later.
    public function testAProcessThatGotTheRecordedNumberLaterIsNotTheRecordedProcess(): void
    {
        $now = RecordedProcess::current();

        self::assertFalse(new RecordedProcess($now->pid, $now->started - 1)->stillRuns());
    }

    public function testAProcessThatEndedNoLongerRuns(): void
    {
        $process = new Process([PHP_BINARY, '-r', '']);
        $process->start();
        $pid = (int) $process->getPid();
        $process->wait();

        self::assertFalse(new RecordedProcess($pid, RecordedProcess::current()->started)->stillRuns());
    }
}
