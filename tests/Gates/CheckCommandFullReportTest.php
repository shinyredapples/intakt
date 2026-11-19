<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\FullReport;
use Intakt\Gates\GateResult;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\ScratchDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(CheckCommand::class)]
#[CoversClass(FullReport::class)]
#[CoversClass(ScratchDirectory::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(ProcessRunner::class)]
final class CheckCommandFullReportTest extends SandboxTestCase
{
    private const string EM_DASH = "\u{2014}";

    private const int DASHED_LINES = 12;

    public function testBinCheckWritesTheFullReportFileAndItsSummaryNamesIt(): void
    {
        $tree = $this->treeWithDashedLines();

        $run = $tree->check('--only=dashes');

        $summary = $this->summaryOf($run);
        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertSame(['dashes'], $summary['failed'] ?? null);
        self::assertMatchesRegularExpression('/^report-[0-9a-f]{16}\.json$/', (string) ($summary['report'] ?? ''), $run->output());
        self::assertSame('2', $run->omittedOf('dashes'));
        $report = $this->decoded((string) file_get_contents($tree->root.'/'.$summary['report']));
        self::assertSame('fail', $report['result'] ?? null);
        self::assertCount(self::DASHED_LINES, $report['gates'][0]['findings'] ?? []);
    }

    public function testWithoutAScratchFolderBinCheckWritesNoReportAndPrintsOnlyJson(): void
    {
        $tree = $this->treeWithDashedLines();
        $missing = $this->emptySandbox()->root.'/missing';

        $result = new ProcessRunner()->run(
            ['php', '-d', 'display_errors=1', '-d', 'sys_temp_dir='.$missing, dirname(__DIR__, 2).'/bin/check', '--only=dashes', '--root='.$tree->root],
            dirname(__DIR__, 2),
        );
        $run = new CheckRun($result);

        foreach ($run->lines() as $line) {
            self::assertIsArray(json_decode($line, true), $line);
        }
        $summary = $this->summaryOf($run);
        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertSame(['dashes'], $summary['failed'] ?? null);
        self::assertArrayNotHasKey('report', $summary);
        self::assertSame([], glob($tree->root.'/report-*.json'));
    }

    private function treeWithDashedLines(): Sandbox
    {
        $tree = $this->emptySandbox();
        $lines = array_map(static fn (int $number): string => 'Line '.$number.' '.self::EM_DASH.' with a long dash.', range(1, self::DASHED_LINES));
        $tree->write('notes.md', implode("\n", $lines)."\n");

        return $tree;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function summaryOf(CheckRun $run): array
    {
        $lines = $run->lines();

        return $this->decoded((string) array_last($lines));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decoded(string $json): array
    {
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded, $json);

        return $decoded;
    }
}
