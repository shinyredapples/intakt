<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\FullReport;
use Intakt\Gates\GateReport;
use Intakt\Gates\GateResult;
use Intakt\Gates\JsonNode;
use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(FullReport::class)]
#[UsesClass(GateReport::class)]
#[UsesClass(GateResult::class)]
#[UsesClass(JsonNode::class)]
#[UsesClass(ProcessResult::class)]
final class FullReportRetentionTest extends SandboxTestCase
{
    private const int OLD = 1_000_000_000;

    public function testWritingAReportKeepsOnlyTheTwentyNewestGeneratedReports(): void
    {
        $root = $this->emptySandbox()->root;
        $seeded = $this->seedReports($root, 25, self::OLD);

        $file = $this->writeReportInto($root);

        $expected = [...array_slice($seeded, 0, 19), basename($file)];
        sort($expected);
        self::assertSame($expected, $this->generatedReportsIn($root));
    }

    public function testNineteenOlderReportsAndTheNewOneAreAllKept(): void
    {
        $root = $this->emptySandbox()->root;
        $this->seedReports($root, 19, self::OLD);

        $this->writeReportInto($root);

        self::assertCount(20, $this->generatedReportsIn($root));
    }

    public function testPruningNeverDeletesAHandMadeReportManualJson(): void
    {
        $root = $this->emptySandbox()->root;
        $this->seedFile($root, 'report-manual.json', self::OLD - 100);
        $this->seedReports($root, 25, self::OLD);

        $this->writeReportInto($root);

        self::assertFileExists($root.'/'.FullReport::FOLDER.'/report-manual.json');
    }

    public function testPruningLeavesNamesThatOnlyLookGeneratedAlone(): void
    {
        $root = $this->emptySandbox()->root;
        $lookalikes = ['report-0123456789ABCDEF.json', 'report-0123456789abcde.json', 'report-0123456789abcdef0.json', 'report-0123456789abcdef.json.bak', 'xreport-0123456789abcdef.json'];
        foreach ($lookalikes as $name) {
            $this->seedFile($root, $name, self::OLD - 100);
        }
        $this->seedReports($root, 25, self::OLD);

        $this->writeReportInto($root);

        foreach ($lookalikes as $name) {
            self::assertFileExists($root.'/'.FullReport::FOLDER.'/'.$name);
        }
    }

    public function testPruningLeavesAHandMadeNameEndingInANewlineAlone(): void
    {
        $root = $this->emptySandbox()->root;
        $this->seedFile($root, "report-0123456789abcdef.json\n", self::OLD - 100);
        $this->seedReports($root, 25, self::OLD);

        $this->writeReportInto($root);

        self::assertFileExists($root.'/'.FullReport::FOLDER."/report-0123456789abcdef.json\n");
    }

    public function testPruningLeavesAFolderNamedLikeAGeneratedReport(): void
    {
        $root = $this->emptySandbox()->root;
        $this->seedReports($root, 25, self::OLD);
        mkdir($root.'/'.FullReport::FOLDER.'/report-0000000000000000.json');
        touch($root.'/'.FullReport::FOLDER.'/report-0000000000000000.json', 1);

        $this->writeReportInto($root);

        self::assertDirectoryExists($root.'/'.FullReport::FOLDER.'/report-0000000000000000.json');
    }

    public function testTheReportJustWrittenIsKeptEvenWhenNineteenOtherReportsAreDatedInTheFuture(): void
    {
        $root = $this->emptySandbox()->root;
        $farFuture = $this->seedReports($root, 19, 4_000_000_000);
        $this->seedReports($root, 5, 3_900_000_000);

        $file = $this->writeReportInto($root);

        $expected = [...$farFuture, basename($file)];
        sort($expected);
        self::assertSame($expected, $this->generatedReportsIn($root));
    }

    public function testPruningLeavesALinkNamedLikeAGeneratedReport(): void
    {
        $sandbox = $this->emptySandbox();
        $outside = $this->emptySandbox();
        $outside->write('target.json', '{}');
        $this->seedReports($sandbox->root, 25, self::OLD);
        $sandbox->symlink(FullReport::FOLDER.'/report-0000000000000000.json', $outside->root.'/target.json');
        touch($outside->root.'/target.json', 1);

        $this->writeReportInto($sandbox->root);

        self::assertTrue(is_link($sandbox->root.'/'.FullReport::FOLDER.'/report-0000000000000000.json'));
        self::assertFileExists($outside->root.'/target.json');
    }

    public function testPruningATreeNamedWithBracketsLeavesItsSiblingTreeAlone(): void
    {
        $parent = $this->emptySandbox()->root;
        mkdir($parent.'/tree[a]');
        mkdir($parent.'/treea');
        $this->seedReports($parent.'/tree[a]', 25, self::OLD);
        $this->seedReports($parent.'/treea', 25, self::OLD);

        $this->writeReportInto($parent.'/tree[a]');

        self::assertCount(20, $this->generatedReportsIn($parent.'/tree[a]'));
        self::assertCount(25, $this->generatedReportsIn($parent.'/treea'));
    }

    /**
     * @return list<string>
     */
    private function seedReports(string $root, int $count, int $time): array
    {
        $names = [];
        for ($index = 0; $index < $count; ++$index) {
            $name = sprintf('report-%016x.json', random_int(1, PHP_INT_MAX));
            $this->seedFile($root, $name, $time - $index);
            $names[] = $name;
        }

        return $names;
    }

    private function writeReportInto(string $root): string
    {
        $result = GateResult::passed('dashes');
        $file = new FullReport($root, [GateReport::fromChild('dashes', new ProcessResult(0, $result->toJson()."\n", ''))])->write();
        self::assertNotNull($file);

        return $file;
    }

    private function seedFile(string $root, string $name, int $time): void
    {
        $folder = $root.'/'.FullReport::FOLDER;
        if (!is_dir($folder)) {
            mkdir($folder, 0o777, true);
        }
        file_put_contents($folder.'/'.$name, '{}');
        touch($folder.'/'.$name, $time);
    }

    /**
     * @return list<string>
     */
    private function generatedReportsIn(string $root): array
    {
        $listed = scandir($root.'/'.FullReport::FOLDER);
        self::assertIsArray($listed);
        $names = array_values(array_filter($listed, static fn (string $name): bool => 1 === preg_match(FullReport::GENERATED, $name)));
        sort($names);

        return $names;
    }
}
