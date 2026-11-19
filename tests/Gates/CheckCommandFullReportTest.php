<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\FullReport;
use Intakt\Gates\GateChildren;
use Intakt\Gates\GateReport;
use Intakt\Gates\GateResult;
use Intakt\Gates\JsonNode;
use Intakt\Gates\ProcessRunner;
use Intakt\Gates\ScratchDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(CheckCommand::class)]
#[CoversClass(FullReport::class)]
#[CoversClass(ScratchDirectory::class)]
#[UsesClass(GateChildren::class)]
#[UsesClass(GateReport::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(JsonNode::class)]
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
        $name = $summary['report'] ?? null;
        self::assertIsString($name, $run->output());
        self::assertMatchesRegularExpression('#\Avar/check/report-[0-9a-f]{16}\.json\z#', $name);
        self::assertSame('2', $run->omittedOf('dashes'));
        $report = JsonNode::parse((string) file_get_contents($tree->root.'/'.$name));
        self::assertInstanceOf(JsonNode::class, $report);
        self::assertSame('fail', $report->get('result')->text());
        self::assertCount(self::DASHED_LINES, array_first($report->get('gates')->items())?->get('findings')->items() ?? []);
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
        self::assertDirectoryDoesNotExist($tree->root.'/'.FullReport::FOLDER);
    }

    public function testAScratchEntryThatCannotBeRemovedStillLeavesTheReportAndItsVerdict(): void
    {
        $this->skipWhenPermissionsAreIgnored();
        $home = $this->homeWhoseGateLeavesAStuckScratchEntry();
        $tree = $this->emptySandbox();
        $temp = $this->emptySandbox()->root;

        try {
            $result = new ProcessRunner()->run(['php', '-d', 'sys_temp_dir='.$temp, $home->root.'/bin/check', '--only=dashes', '--root='.$tree->root], $home->root);
        } finally {
            $stuck = glob($temp.'/*/stuck');
            array_map(static fn (string $folder): bool => chmod($folder, 0o700), false === $stuck ? [] : $stuck);
        }

        $run = new CheckRun($result);
        self::assertNotSame([], glob($temp.'/*/stuck/entry'), 'the scratch folder was removed, so the cleanup never failed');
        self::assertSame(['{"gate":"dashes","ok":false,"findings":["dashes failed on purpose"],"omitted":0}', '{"result":"fail","failed":["dashes"]}'], $run->linesWithoutTheReportName());
        self::assertSame(1, $run->exitCode(), $run->output());
        $name = $this->summaryOf($run)['report'] ?? null;
        self::assertIsString($name, $run->output());
        self::assertFileExists($tree->root.'/'.$name);
    }

    public function testTheReportOfAnEarlierRunInAGitCheckoutWithoutTheIgnoreRuleDoesNotFailTheNextRun(): void
    {
        $tree = $this->emptySandbox();
        $tree->startRepository();
        $tree->commitAll('Base');
        $tree->write('note'.self::EM_DASH.'.md', 'A line '.self::EM_DASH.' with a long dash.'."\n");
        $first = $tree->check('--base=HEAD', '--only=dashes');
        self::assertSame(1, $first->exitCode(), $first->output());
        self::assertIsString($this->summaryOf($first)['report'] ?? null, $first->output());
        unlink($tree->root.'/note'.self::EM_DASH.'.md');

        $second = $tree->check('--base=HEAD', '--only=dashes');

        self::assertSame(0, $second->exitCode(), $second->output());
    }

    // Because the children run the home's own child entry, a home whose entry makes the scratch folder unremovable fails the cleanup of a real run.
    private function homeWhoseGateLeavesAStuckScratchEntry(): Sandbox
    {
        $real = dirname(__DIR__, 2);
        $home = $this->sandboxWith('stuck-scratch');
        $home->write('bin/check', (string) file_get_contents($real.'/bin/check'));
        $home->symlink('vendor', $real.'/vendor');

        return $home;
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
