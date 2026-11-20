<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\FullReport;
use Intakt\Gates\JsonNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(CheckCommand::class)]
#[UsesClass(FullReport::class)]
final class CheckCommandFullReportTest extends SandboxTestCase
{
    private const string EM_DASH = "\u{2014}";

    public function testBinCheckWritesTheFullReportAndItsSummaryNamesIt(): void
    {
        $sandbox = $this->sandboxWithTwelveLongDashes();

        $run = $sandbox->check('--only=dashes');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertSame('2', $run->omittedOf('dashes'));
        self::assertMatchesRegularExpression('#^var/check/report-[0-9a-f]{16}\.json$#', $this->reportNamedBy($run));
        self::assertCount(12, $this->findingsInTheReport($sandbox, $run));
    }

    public function testTheSummaryKeepsTheVerdictAndTheFailedListOfTheGateLines(): void
    {
        $run = $this->sandboxWithTwelveLongDashes()->check('--only=dashes');

        $summary = JsonNode::parse((string) array_last($run->lines()));

        self::assertNotNull($summary);
        self::assertSame('fail', $summary->get('result')->text());
        self::assertSame(['dashes'], array_map(static fn (JsonNode $gate): string => $gate->text(), $summary->get('failed')->items()));
    }

    private function sandboxWithTwelveLongDashes(): Sandbox
    {
        $sandbox = $this->emptySandbox();

        foreach (range(1, 12) as $number) {
            $sandbox->write('docs/page-'.$number.'.md', 'A claim '.self::EM_DASH." and its reason.\n");
        }

        return $sandbox;
    }

    private function reportNamedBy(CheckRun $run): string
    {
        return JsonNode::parse((string) array_last($run->lines()))?->get('report')->text() ?? '';
    }

    /**
     * @return list<mixed>
     */
    private function findingsInTheReport(Sandbox $sandbox, CheckRun $run): array
    {
        $report = json_decode((string) file_get_contents($sandbox->root.'/'.$this->reportNamedBy($run)), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);

        return array_values(array_filter(
            $report['gates'][0]['findings'] ?? [],
            static fn (mixed $finding): bool => is_string($finding) && str_contains($finding, 'U+2014'),
        ));
    }
}
