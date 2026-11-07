<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateReport;
use Intakt\Gates\GateResult;
use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GateReport::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(ProcessResult::class)]
final class GateReportLongOutputTest extends TestCase
{
    public function testATimedOutChildWithLongOutputStillNamesTheTimeout(): void
    {
        $errors = str_repeat("PHP Deprecated: something noisy\n", 20).'php timed out after 3600 seconds';

        $report = GateReport::fromChild('tests', new ProcessResult(124, str_repeat("progress\n", 50), $errors));

        self::assertFalse($report->isPassing());
        self::assertStringContainsString('exit code 124 and no valid result line: ...', $report->line);
        self::assertStringEndsWith('something noisy php timed out after 3600 seconds"],"omitted":0}', $report->line);
    }

    public function testAChildWithShortOutputKeepsAllOfItUncut(): void
    {
        $report = GateReport::fromChild('tests', new ProcessResult(255, "first output\n", 'PHP Fatal error: boom'));

        self::assertStringContainsString('no valid result line: first output PHP Fatal error: boom"', $report->line);
    }
}
