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
final class GateReportSingleLineTest extends TestCase
{
    public function testAPrettyPrintedResultObjectFailsTheGateOnOneLine(): void
    {
        $output = json_encode(['gate' => 'cs', 'ok' => true, 'findings' => [], 'omitted' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $report = GateReport::fromChild('cs', new ProcessResult(0, $output, ''));

        self::assertFalse($report->isPassing());
        self::assertStringNotContainsString("\n", $report->line);
        self::assertStringContainsString('exit code 0 and no valid result line', $report->line);
    }

    public function testAResultObjectSplitByACarriageReturnFailsTheGate(): void
    {
        $report = GateReport::fromChild('cs', new ProcessResult(0, "{\"gate\":\"cs\",\r\"ok\":true,\"findings\":[],\"omitted\":0}", ''));

        self::assertFalse($report->isPassing());
        self::assertStringNotContainsString("\r", $report->line);
    }

    public function testACompactResultLineWithATrailingNewlinePasses(): void
    {
        $report = GateReport::fromChild('cs', new ProcessResult(0, "{\"gate\":\"cs\",\"ok\":true,\"findings\":[],\"omitted\":0}\n", ''));

        self::assertTrue($report->isPassing());
        self::assertSame('{"gate":"cs","ok":true,"findings":[],"omitted":0}', $report->line);
    }
}
