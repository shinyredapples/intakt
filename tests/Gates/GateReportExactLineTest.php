<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\GateReport;
use Intakt\Gates\GateResult;
use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GateReport::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(ProcessResult::class)]
final class GateReportExactLineTest extends TestCase
{
    private const string PASSING = '{"gate":"cs","ok":true,"findings":[],"omitted":0}';

    /**
     * @return iterable<string, array{string}>
     */
    public static function looseOutputs(): iterable
    {
        yield 'wrapped in NUL bytes' => ["\0".self::PASSING."\0\n"];
        yield 'a leading space' => [' '.self::PASSING."\n"];
        yield 'a trailing space' => [self::PASSING." \n"];
        yield 'a vertical tab' => ["\v".self::PASSING."\n"];
        yield 'a repeated ok key' => ['{"gate":"cs","ok":false,"ok":true,"findings":[],"omitted":0}'."\n"];
        yield 'findings as an object' => ['{"gate":"cs","ok":true,"findings":{},"omitted":0}'."\n"];
        yield 'an escaped slash' => ['{"gate":"cs","ok":true,"findings":[],"omitted":0,"note":"a\/b"}'."\n"];
        yield 'no trailing newline' => [self::PASSING];
        yield 'two trailing newlines' => [self::PASSING."\n\n"];
        yield 'a carriage return before the newline' => [self::PASSING."\r\n"];
    }

    #[DataProvider('looseOutputs')]
    public function testOutputThatIsNotExactlyOneEncodedResultLineFailsTheGate(string $output): void
    {
        $report = GateReport::fromChild('cs', new ProcessResult(0, $output, ''));

        self::assertFalse($report->isPassing());
        self::assertStringContainsString('exit code 0 and no valid result line', $report->line);
    }

    public function testTheLineGateResultWritesWithOneNewlinePasses(): void
    {
        $line = GateResult::passedBecause('cs', 'skipped a/b ü')->withSlowest(['tests/A.php' => 1.0, 'tests/B.php' => 0.1234])->toJson();

        $report = GateReport::fromChild('cs', new ProcessResult(0, $line."\n", ''));

        self::assertTrue($report->isPassing());
        self::assertSame($line, $report->line);
    }
}
