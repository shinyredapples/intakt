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
final class GateReportTest extends TestCase
{
    private const string PASSING = '{"gate":"cs","ok":true,"findings":[],"omitted":0}';

    private const string FAILING = '{"gate":"cs","ok":false,"findings":["a.php:1 bad"],"omitted":0,"note":"why"}';

    public function testAPassingLineWithExitCodeZeroIsKeptAsItIs(): void
    {
        $report = GateReport::fromChild('cs', new ProcessResult(0, self::PASSING."\n", 'noise'));

        self::assertTrue($report->passing);
        self::assertSame(self::PASSING, $report->line);
    }

    public function testAFailingLineWithExitCodeOneIsKeptAsItIs(): void
    {
        $report = GateReport::fromChild('cs', new ProcessResult(1, self::FAILING."\n", ''));

        self::assertFalse($report->passing);
        self::assertSame(self::FAILING, $report->line);
    }

    public function testAChildThatPrintsNothingFailsTheGate(): void
    {
        $report = GateReport::fromChild('cs', new ProcessResult(0, '', ''));

        self::assertFalse($report->passing);
        self::assertStringContainsString('"gate":"cs","ok":false', $report->line);
    }

    public function testAChildThatTimedOutFailsTheGateWithItsReason(): void
    {
        $report = GateReport::fromChild('cs', new ProcessResult(124, '', 'php timed out after 3600 seconds'));

        self::assertFalse($report->passing);
        self::assertStringContainsString('php timed out after 3600 seconds', $report->line);
    }

    public function testALineOfAnotherGateFailsTheGate(): void
    {
        $report = GateReport::fromChild('phpstan', new ProcessResult(0, self::PASSING, ''));

        self::assertFalse($report->passing);
        self::assertStringContainsString('"gate":"phpstan","ok":false', $report->line);
    }

    public function testAPassingLineWithAFailingExitCodeFailsTheGate(): void
    {
        self::assertFalse(GateReport::fromChild('cs', new ProcessResult(255, self::PASSING, ''))->passing);
    }

    public function testALineThatPassesDespiteFindingsFailsTheGate(): void
    {
        $line = '{"gate":"cs","ok":true,"findings":["a.php:1 bad"],"omitted":0}';

        self::assertFalse(GateReport::fromChild('cs', new ProcessResult(0, $line, ''))->passing);
    }

    public function testALineThatPassesDespiteOmittedFindingsFailsTheGate(): void
    {
        $line = '{"gate":"cs","ok":true,"findings":[],"omitted":3}';

        self::assertFalse(GateReport::fromChild('cs', new ProcessResult(0, $line, ''))->passing);
    }

    public function testALineWithAnUnknownFieldFailsTheGate(): void
    {
        $line = '{"gate":"cs","ok":true,"findings":[],"omitted":0,"skip":true}';

        self::assertFalse(GateReport::fromChild('cs', new ProcessResult(0, $line, ''))->passing);
    }

    public function testTwoResultLinesFailTheGate(): void
    {
        self::assertFalse(GateReport::fromChild('cs', new ProcessResult(0, self::PASSING."\n".self::PASSING, ''))->passing);
    }
}
