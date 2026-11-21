<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\BenchHeadline;
use Intakt\Core\Dashboard\Infrastructure\PublishedBench;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishedBench::class)]
#[CoversClass(BenchHeadline::class)]
final class PublishedBenchTest extends TestCase
{
    private const string HEADER = '| Reviewer | Defects found (measured) | False alarms (measured) | Tokens per change (measured) |';
    private const string PANEL = '| Panel (`correctness` and `security`) | 11 of 15 | 2 | about 6,800 |';
    private const string DATE = 'Date of the run: 2026-11-16. Models: a, b.';

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
        self::assertNotNull($this->headlineOf($this->section(header: '| Reviewer | Defects found | False alarms |')));
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

    public function testTheShippedReadmeStillParses(): void
    {
        $headline = new PublishedBench(__DIR__.'/../../../../README.md')->headline();

        self::assertNotNull($headline);
        self::assertMatchesRegularExpression('/^\d+ of \d+$/', $headline->reading());
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $headline->ranOn);
    }

    private function section(string $header = self::HEADER, string $panel = self::PANEL, string $date = self::DATE): string
    {
        return "# Intakt\n\n### Review bench\n\nThe bench, below:\n\n```\nphp bin/console intakt:bench\n```\n\n"
            ."{$header}\n| --- | --- | --- | --- |\n{$panel}\n| `security` alone | 8 of 15 | 0 | about 4,350 |\n\n{$date}\n";
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
