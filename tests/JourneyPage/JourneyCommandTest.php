<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class JourneyCommandTest extends TestCase
{
    private const string COMMAND = __DIR__.'/../../bin/journey';
    private const string FIXTURES = __DIR__.'/Fixtures';
    private const array DRAWINGS = ['tokens-by-lane', 'tokens-by-account', 'tokens-per-merge', 'issues-per-day', 'open-issues', 'timeline'];
    private const array TOKEN_CHARTS = ['tokens-by-lane', 'tokens-by-account', 'tokens-per-merge'];

    private string $root = '';

    public function testTheJourneyCommandIsExecutable(): void
    {
        self::assertTrue(is_executable(self::COMMAND));
    }

    public function testTheCommandWritesTheTablesFromTheRecordedAnswers(): void
    {
        $run = $this->runJourney();

        $page = $this->page();
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertStringContainsString('docs/journey.md regenerated', $run->getOutput());
        self::assertMatchesRegularExpression('/\| Pull requests merged \| 3 counted \(to \d{4}-\d{2}-\d{2}\) \|/', $page);
        self::assertStringContainsString('| M0 Unblock | fixes the build loop needed first | 10-27 | 11-05 | 2 / 0 |', $page);
    }

    public function testTheCommandWritesTheExpectedDrawingsFromTheRecordedAnswersAndTheUsageFile(): void
    {
        $this->runJourney();

        self::assertSame(array_map($this->expected(...), self::DRAWINGS), array_map($this->drawing(...), self::DRAWINGS));
    }

    public function testTheCommandKeepsTheTextOutsideTheMarkers(): void
    {
        $original = (string) file_get_contents(self::FIXTURES.'/journey.md');

        $this->runJourney();

        self::assertSame($this->outsideMarkers($original), $this->outsideMarkers($this->page()));
    }

    public function testASecondRunWithTheSameInputsChangesNothing(): void
    {
        $this->runJourney();
        $first = [$this->page(), ...array_map($this->drawing(...), self::DRAWINGS)];

        $second = $this->runJourney();

        self::assertSame($first, [$this->page(), ...array_map($this->drawing(...), self::DRAWINGS)]);
        self::assertSame("docs/journey.md and its drawings are current\n", $second->getOutput());
    }

    public function testTheTokenChartsStayTheSameWhileTheUsageFileDoesEvenAfterAnotherMergeOnAUsageDay(): void
    {
        $this->runJourney();
        $tokens = [array_map($this->drawing(...), self::TOKEN_CHARTS), $this->region('tokens-per-merge')];
        $issues = $this->drawing('issues-per-day');

        $later = $this->runJourney(self::FIXTURES.'/api-later');

        self::assertSame($tokens, [array_map($this->drawing(...), self::TOKEN_CHARTS), $this->region('tokens-per-merge')]);
        self::assertNotSame($issues, $this->drawing('issues-per-day'));
        self::assertStringNotContainsString('tokens-', $later->getOutput());
    }

    public function testAChangedUsageFileCountsTheMergesOfItsDaysAgain(): void
    {
        $this->runJourney();
        file_put_contents($this->root.'/docs/journey/usage.jsonl', "\n", FILE_APPEND);

        $this->runJourney(self::FIXTURES.'/api-later');

        self::assertStringContainsString('| 10-27 | 3,500,000 |', $this->region('tokens-per-merge'));
    }

    public function testAKeptMergeCountOfZeroIsCountedAgainFromTheRecordedAnswers(): void
    {
        $this->runJourney();
        $counted = $this->page();
        file_put_contents($this->root.'/docs/journey.md', str_replace(': 2026-10-26=2 2026-10-27=1 -->', ': 2026-10-26=0 2026-10-27=1 -->', $counted));

        $run = $this->runJourney();

        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertSame("docs/journey.md regenerated\n", $run->getOutput());
        self::assertSame($counted, $this->page());
    }

    public function testAPageWithoutMarkersIsLeftUnwritten(): void
    {
        file_put_contents($this->root.'/docs/journey.md', "No markers here.\n");

        $run = $this->runJourney();

        self::assertSame(1, $run->getExitCode());
        self::assertStringContainsString('begin and one end marker', $run->getErrorOutput());
        self::assertSame("No markers here.\n", $this->page());
        self::assertSame([], glob($this->root.'/docs/journey/*.svg'));
    }

    public function testAPageWithoutATimelineBlockIsLeftUnwritten(): void
    {
        $page = str_replace("```mermaid\ntimeline", "```mermaid\nflowchart", (string) file_get_contents(self::FIXTURES.'/journey.md'));
        file_put_contents($this->root.'/docs/journey.md', $page);

        $run = $this->runJourney();

        self::assertSame(1, $run->getExitCode());
        self::assertStringContainsString('exactly one mermaid timeline block', $run->getErrorOutput());
        self::assertSame($page, $this->page());
        self::assertSame([], glob($this->root.'/docs/journey/*.svg'));
    }

    public function testADrawingThatCannotBeWrittenStopsTheRunBeforeThePage(): void
    {
        mkdir($this->root.'/docs/journey/tokens-by-lane.svg');

        $run = $this->runJourney();

        self::assertSame(1, $run->getExitCode());
        self::assertSame("cannot write docs/journey/tokens-by-lane.svg\n", $run->getErrorOutput());
        self::assertSame((string) file_get_contents(self::FIXTURES.'/journey.md'), $this->page());
    }

    public function testAnUnknownOptionIsAUsageError(): void
    {
        $run = new Process([PHP_BINARY, 'bin/journey', '--bogus'], $this->root);
        $run->run();

        self::assertSame(2, $run->getExitCode());
        self::assertStringContainsString('usage:', $run->getErrorOutput());
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-journey-'.bin2hex(random_bytes(6));
        $files = new Filesystem();
        $files->mkdir($this->root.'/bin');
        $files->mkdir($this->root.'/docs/journey');
        $files->copy(self::COMMAND, $this->root.'/bin/journey');
        $files->symlink((string) realpath(__DIR__.'/../../vendor'), $this->root.'/vendor');
        $files->copy(self::FIXTURES.'/journey.md', $this->root.'/docs/journey.md');
        $files->copy(self::FIXTURES.'/usage.jsonl', $this->root.'/docs/journey/usage.jsonl');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    private function runJourney(string $recorded = self::FIXTURES.'/api'): Process
    {
        $process = new Process([PHP_BINARY, 'bin/journey', '--recorded='.realpath($recorded)], $this->root);
        $process->run();

        return $process;
    }

    private function page(): string
    {
        return (string) file_get_contents($this->root.'/docs/journey.md');
    }

    private function region(string $name): string
    {
        preg_match('/<!-- journey:begin '.$name.' -->\n(.*?)<!-- journey:end '.$name.' -->/s', $this->page(), $region);

        return $region[1] ?? '';
    }

    private function expected(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.'/expected/'.$name.'.svg');
    }

    private function drawing(string $name): string
    {
        return (string) file_get_contents($this->root.'/docs/journey/'.$name.'.svg');
    }

    private function outsideMarkers(string $page): string
    {
        return (string) preg_replace('/(<!-- journey:begin [a-z-]+ -->\n).*?(<!-- journey:end [a-z-]+ -->)/s', '$1$2', $page);
    }
}
