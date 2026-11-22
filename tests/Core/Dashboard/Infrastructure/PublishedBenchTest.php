<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\BenchHeadline;
use Intakt\Core\Dashboard\Infrastructure\MarkdownSection;
use Intakt\Core\Dashboard\Infrastructure\MarkdownTable;
use Intakt\Core\Dashboard\Infrastructure\PublishedBench;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishedBench::class)]
#[CoversClass(MarkdownSection::class)]
#[CoversClass(MarkdownTable::class)]
#[CoversClass(BenchHeadline::class)]
final class PublishedBenchTest extends TestCase
{
    private const string HEADER = '| Reviewer | Defects found (measured) | False alarms (measured) | Tokens per change (measured) |';
    private const string PANEL = '| Panel (`correctness` and `security`) | 11 of 15 | 2 | about 6,800 |';
    private const string DATE = 'Date of the run: 2026-11-16. Models: a, b.';
    private const string SMALL_HEADER = '| Reviewer | Defects found | False alarms |';
    private const string DELIMITER = '| --- | --- | --- |';
    private const string SMALL_PANEL = '| Panel | 11 of 15 | 2 |';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->files);
    }

    public function testTheReadmeTableGivesThePanelsResultAndTheDateOfItsRun(): void
    {
        $headline = $this->headlineOf($this->section());

        self::assertNotNull($headline);
        self::assertSame(['11 of 15', 2, '2026-11-16'], [$headline->reading(), $headline->falseAlarms, $headline->ranOn]);
    }

    public function testAReadmeWithoutTheTableGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf("# Intakt\n"));
        self::assertNull(new PublishedBench(sys_get_temp_dir().'/no-such-readme.md')->headline());
    }

    public function testATableWithoutTheReviewBenchHeadingGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf(str_replace('### Review bench', '### Other bench', $this->section())));
    }

    public function testARenamedThirdColumnGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->section(header: '| Reviewer | Defects found (measured) | Runs | Tokens |')));
    }

    public function testSwappedColumnsGiveNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->section(header: '| Reviewer | False alarms (measured) | Defects found (measured) | Tokens |')));
    }

    public function testAColumnThatOnlyStartsWithTheNameGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->section(header: '| Reviewer | Defects found (measured) | False alarms per change | Tokens |')));
    }

    public function testColumnsNamedWithoutTheirQualifierStillGiveTheHeadline(): void
    {
        self::assertNotNull($this->headlineOf($this->section(header: '| Reviewer | Defects found | False alarms | Tokens |')));
    }

    public function testAPanelRowOutsideTheSectionGivesNoHeadline(): void
    {
        $readme = $this->section(panel: '| `security` alone | 8 of 15 | 0 | about 4,350 |')."\n## Found and fixed upstream\n\n".self::HEADER."\n".self::PANEL."\n";

        self::assertNull($this->headlineOf($readme));
    }

    public function testARunDateOutsideTheSectionGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->section(date: '')."\n## Later\n\n".self::DATE."\n"));
    }

    public function testARunDateThatIsNoCalendarDayGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->section(date: 'Date of the run: 2026-13-40. Models: a.')));
    }

    public function testTwoRunDatesGiveNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->section(date: self::DATE."\n\n".self::DATE)));
    }

    public function testAPanelRowWithoutAFalseAlarmCountGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->section(panel: '| Panel | 11 of 15 | two | about 6,800 |')));
    }

    public function testTwoPanelRowsGiveNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->section(panel: self::PANEL."\n".self::PANEL)));
    }

    public function testATableWithoutADelimiterRowGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->bench(self::SMALL_HEADER."\n".self::SMALL_PANEL)));
    }

    public function testAPanelRowInASecondTableOfTheSectionGivesNoHeadline(): void
    {
        $tables = $this->table('| Solo | 8 of 15 | 0 |')."\n\n| Reviewer | Defects found | Runs |\n".self::DELIMITER."\n".self::SMALL_PANEL;

        self::assertNull($this->headlineOf($this->bench($tables)));
    }

    public function testASecondTableAfterTheBenchTableGivesNoHeadline(): void
    {
        $tables = $this->table(self::SMALL_PANEL)."\n\n| Reviewer | Runs | Days |\n".self::DELIMITER."\n| Panel | 3 of 4 | 5 |";

        self::assertNull($this->headlineOf($this->bench($tables)));
    }

    public function testAnEmptyColumnBeforeTheReviewerGivesNoHeadline(): void
    {
        $table = '| '.self::SMALL_HEADER."\n| --- | --- | --- | --- |\n| ".self::SMALL_PANEL;

        self::assertNull($this->headlineOf($this->bench($table)));
    }

    public function testAFalseAlarmColumnInAnotherUnitGivesNoHeadline(): void
    {
        $table = "| Reviewer | Defects found | False alarms (per change) |\n".self::DELIMITER."\n".self::SMALL_PANEL;

        self::assertNull($this->headlineOf($this->bench($table)));
    }

    public function testARowOfAnotherWidthGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->bench($this->table('| Panel | 11 of 15 | 2 | 6,800 |'))));
    }

    public function testAnEscapedPipeIsNoColumnBoundary(): void
    {
        self::assertNull($this->headlineOf($this->bench($this->table('| Panel \| 11 of 15 | 2 |'))));
    }

    public function testAPanelAfterATabDelimitedHeadingGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->bench("##\tLater\n\n".$this->table(self::SMALL_PANEL))));
    }

    public function testAPanelAfterASetextHeadingGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->bench("Later\n-----\n\n".$this->table(self::SMALL_PANEL))));
        self::assertNull($this->headlineOf($this->bench("Later\n=====\n\n".$this->table(self::SMALL_PANEL))));
    }

    public function testAFourthLevelHeadingKeepsTheSection(): void
    {
        self::assertNotNull($this->headlineOf($this->bench("#### Figures\n\n".$this->table(self::SMALL_PANEL))));
    }

    public function testASectionInsideFencedCodeGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf("```markdown\n".$this->bench($this->table(self::SMALL_PANEL))."```\n"));
        self::assertNull($this->headlineOf("~~~~\n".$this->bench($this->table(self::SMALL_PANEL))."~~~\n"));
    }

    public function testARunDateInsideFencedCodeGivesNoHeadline(): void
    {
        $readme = "### Review bench\n\n".$this->table(self::SMALL_PANEL)."\n\n```text\nDate of the run: 2026-11-16.\n```\n";

        self::assertNull($this->headlineOf($readme));
    }

    public function testTwoReviewBenchSectionsGiveNoHeadline(): void
    {
        $bench = $this->bench($this->table(self::SMALL_PANEL));

        self::assertNull($this->headlineOf($bench."\n".$bench));
    }

    public function testMoreDefectsFoundThanPlantedGiveNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->bench($this->table('| Panel | 16 of 15 | 2 |'))));
    }

    public function testNothingPlantedGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->bench($this->table('| Panel | 0 of 0 | 2 |'))));
    }

    public function testACommentedOutSectionGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf("<!--\n".$this->bench($this->table(self::SMALL_PANEL))."-->\n"));
    }

    public function testACommentedOutRunDateGivesNoHeadline(): void
    {
        $readme = "### Review bench\n\n".$this->table(self::SMALL_PANEL)."\n\n<!--\nDate of the run: 2026-11-16.\n-->\n";

        self::assertNull($this->headlineOf($readme));
    }

    public function testACommentOnTheRunDateLineKeepsTheDate(): void
    {
        $readme = "### Review bench\n\n".$this->table(self::SMALL_PANEL)."\n\nDate of the run: 2026-11-16. <!-- a note -->\n";

        self::assertSame('2026-11-16', $this->headlineOf($readme)?->ranOn);
    }

    public function testARunDateThatIsTheTitleOfTheNextSetextHeadingGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->bench($this->table(self::SMALL_PANEL))."===\n"));
    }

    public function testAThematicBreakAfterANestedHeadingKeepsTheSection(): void
    {
        self::assertNotNull($this->headlineOf($this->bench("#### Figures\n---\n\n".$this->table(self::SMALL_PANEL))));
    }

    public function testATableWithoutOuterPipesGivesTheHeadline(): void
    {
        $table = "Reviewer | Defects found | False alarms\n--- | --- | ---\nPanel | 11 of 15 | 2";

        self::assertSame('11 of 15', $this->headlineOf($this->bench($table))?->reading());
    }

    public function testASecondTableWithoutOuterPipesGivesNoHeadline(): void
    {
        $tables = $this->table(self::SMALL_PANEL)."\n\nReviewer | Defects found | False alarms\n--- | --- | ---\nPanel | 3 of 4 | 5";

        self::assertNull($this->headlineOf($this->bench($tables)));
    }

    public function testACountBeyondTheIntegerRangeGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->bench($this->table('| Panel | 11 of 15 | '.PHP_INT_MAX.'0 |'))));
        self::assertNull($this->headlineOf($this->bench($this->table('| Panel | '.PHP_INT_MAX.'0 of '.PHP_INT_MAX.'1 | 2 |'))));
    }

    public function testAZeroPaddedCountGivesNoHeadline(): void
    {
        self::assertNull($this->headlineOf($this->bench($this->table('| Panel | 02 of 15 | 2 |'))));
    }

    public function testAllDefectsFoundAtTheLargestCountStillGiveTheHeadline(): void
    {
        $headline = $this->headlineOf($this->bench($this->table('| Panel | 15 of 15 | '.PHP_INT_MAX.' |')));

        self::assertNotNull($headline);
        self::assertSame(['15 of 15', PHP_INT_MAX], [$headline->reading(), $headline->falseAlarms]);
    }

    public function testARepeatSentenceOnItsOwnLineGivesTheRunCount(): void
    {
        self::assertSame(3, $this->headlineOf($this->section(date: self::DATE."\n\nEach change ran 3 times."))?->runs);
    }

    public function testARepeatSentenceInTheMiddleOfALineGivesTheRunCount(): void
    {
        self::assertSame(5, $this->headlineOf($this->section(date: 'Date of the run: 2026-11-16. Each change ran 5 times. Models: a.'))?->runs);
    }

    public function testTwoRepeatSentencesOnOneLineGiveNoRunCountButKeepTheTotals(): void
    {
        $headline = $this->headlineOf($this->section(date: self::DATE."\n\nEach change ran 3 times. Each change ran 5 times."));

        self::assertNotNull($headline);
        self::assertSame(['11 of 15', null], [$headline->reading(), $headline->runs]);
    }

    public function testTwoRepeatSentencesOnSeparateLinesGiveNoRunCount(): void
    {
        $headline = $this->headlineOf($this->section(date: self::DATE."\n\nEach change ran 3 times.\n\nEach change ran 3 times."));

        self::assertNotNull($headline);
        self::assertNull($headline->runs);
    }

    public function testASectionWithoutARepeatSentenceGivesNoRunCount(): void
    {
        $headline = $this->headlineOf($this->section());

        self::assertNotNull($headline);
        self::assertNull($headline->runs);
    }

    public function testARepeatSentenceOutsideTheSectionGivesNoRunCount(): void
    {
        self::assertNull($this->headlineOf($this->section()."\n## Later\n\nEach change ran 3 times.\n")?->runs);
    }

    public function testARepeatSentenceInsideFencedCodeGivesNoRunCount(): void
    {
        self::assertNull($this->headlineOf($this->section(date: self::DATE."\n\n```text\nEach change ran 3 times.\n```"))?->runs);
    }

    public function testZeroOrZeroPaddedRunsGiveNoRunCount(): void
    {
        self::assertNull($this->headlineOf($this->section(date: self::DATE."\n\nEach change ran 0 times."))?->runs);
        self::assertNull($this->headlineOf($this->section(date: self::DATE."\n\nEach change ran 03 times."))?->runs);
    }

    public function testAMalformedSecondRepeatSentenceGivesNoRunCountButKeepsTheTotals(): void
    {
        foreach (['03', '0'] as $other) {
            $headline = $this->headlineOf($this->section(date: self::DATE."\n\nEach change ran 3 times. Each change ran {$other} times."));

            self::assertNotNull($headline);
            self::assertNull($headline->runs);
        }
    }

    public function testOneRunAndARunCountBeyondTheIntegerRange(): void
    {
        self::assertSame(1, $this->headlineOf($this->section(date: self::DATE."\n\nEach change ran 1 times."))?->runs);
        self::assertNull($this->headlineOf($this->section(date: self::DATE."\n\nEach change ran ".PHP_INT_MAX.'0 times.'))?->runs);
    }

    public function testAReadmePathThatIsAFolderGivesNoHeadline(): void
    {
        self::assertNull(new PublishedBench(__DIR__)->headline());
    }

    public function testTheShippedReadmeStillParses(): void
    {
        $headline = new PublishedBench(__DIR__.'/../../../../README.md')->headline();

        self::assertNotNull($headline);
        self::assertMatchesRegularExpression('/^\d+ of \d+$/', $headline->reading());
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $headline->ranOn);
        self::assertSame(3, $headline->runs);
    }

    private function section(string $header = self::HEADER, string $panel = self::PANEL, string $date = self::DATE): string
    {
        return "# Intakt\n\n### Review bench\n\nThe bench, below:\n\n```\nphp bin/console intakt:bench\n```\n\n"
            ."{$header}\n| --- | --- | --- | --- |\n{$panel}\n| `security` alone | 8 of 15 | 0 | about 4,350 |\n\n{$date}\n";
    }

    private function bench(string $body): string
    {
        return "### Review bench\n\n{$body}\n\nDate of the run: 2026-11-16.\n";
    }

    private function table(string $rows): string
    {
        return self::SMALL_HEADER."\n".self::DELIMITER."\n".$rows;
    }

    private function headlineOf(string $text): ?BenchHeadline
    {
        $file = tempnam(sys_get_temp_dir(), 'readme');
        self::assertIsString($file);
        file_put_contents($file, $text);
        $this->files[] = $file;

        return new PublishedBench($file)->headline();
    }
}
