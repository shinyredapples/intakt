<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class PagesCommandTest extends TestCase
{
    private const string BIN = __DIR__.'/../../bin';
    private const string FIXTURES = __DIR__.'/Fixtures';
    private const string JOURNEY_PAGE = __DIR__.'/../../docs/journey.md';
    private const string FRONT_PAGE = __DIR__.'/../../docs/pages';
    // Because the site is read on phones too, each picture, font and script stays small.
    private const int LARGEST_ASSET = 150_000;
    private const array DRAWINGS = ['tokens-by-lane', 'tokens-by-account', 'tokens-per-merge', 'issues-per-day', 'open-issues', 'timeline'];

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-pages-'.bin2hex(random_bytes(6));
        $files = new Filesystem();
        $files->mkdir([$this->root.'/docs/journey', $this->root.'/docs/highlights.d']);

        foreach (['pages', 'highlights', 'journey'] as $command) {
            $files->copy(self::BIN.'/'.$command, $this->root.'/bin/'.$command);
        }

        $files->symlink((string) realpath(__DIR__.'/../../vendor'), $this->root.'/vendor');
        $files->mirror(self::FRONT_PAGE, $this->root.'/docs/pages');
        $files->copy(self::FIXTURES.'/journey.md', $this->root.'/docs/journey.md');
        $files->copy(self::FIXTURES.'/usage.jsonl', $this->root.'/docs/journey/usage.jsonl');
        $files->dumpFile($this->root.'/docs/highlights.d/layout.md', "# Highlights\n\n## Tooling\n\n| Feature | Status |\n|---|---|\n");
        $files->dumpFile($this->root.'/docs/highlights.d/1-clock.md', "## Tooling\n| Clock | done |\n");
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testThePagesCommandIsExecutable(): void
    {
        self::assertTrue(is_executable(self::BIN.'/pages'));
    }

    public function testItWritesTheHighlightsPageAndTheJourneyPageFromTheRecordedAnswersAndTheUsageFile(): void
    {
        $run = $this->runPages();

        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertStringContainsString('| Clock | done |', $this->site('highlights/index.md'));
        self::assertMatchesRegularExpression('/\| Pull requests merged \| 3 counted \(to \d{4}-\d{2}-\d{2}\) \|/', $this->site('journey/index.md'));
    }

    public function testEveryChartAndTheTimelineIsAnImageOfTheJourneyPageAndLiesBesideIt(): void
    {
        $this->runPages();

        foreach (self::DRAWINGS as $name) {
            self::assertStringContainsString('('.$name.'.svg)', $this->site('journey/index.md'));
            self::assertSame($this->expected($name), $this->site('journey/'.$name.'.svg'));
        }
    }

    public function testTheJourneyPageHoldsNoPathThatOnlyResolvesFromDocs(): void
    {
        $this->runPages();

        self::assertStringNotContainsString('](journey/', $this->site('journey/index.md'));
    }

    public function testTheRepositorysJourneyPageLinksNothingUnderJourneyButTheDrawingsThePagesSiteHolds(): void
    {
        preg_match_all('/\]\(journey\/([^)\s]*)/', (string) file_get_contents(self::JOURNEY_PAGE), $links);

        self::assertNotSame([], $links[1]);

        foreach ($links[1] as $target) {
            self::assertMatchesRegularExpression('/^[^\/]+\.svg$/', $target, 'bin/pages copies only docs/journey/*.svg beside the page, so journey/'.$target.' would not resolve on the site');
            self::assertContains(basename($target, '.svg'), self::DRAWINGS, 'the site holds no drawing journey/'.$target);
        }
    }

    public function testTheTimelineImageTakesThePlaceOfTheMermaidBlockThatPagesCannotRender(): void
    {
        $this->runPages();

        self::assertStringNotContainsString('```mermaid', $this->site('journey/index.md'));
        self::assertStringContainsString("\n![Timeline](timeline.svg)\n", $this->site('journey/index.md'));
    }

    public function testTheHighlightsPageLinksTheJourneyPageRelatively(): void
    {
        $this->runPages();

        self::assertStringStartsWith("# Highlights\n\nHow Intakt was built, with its milestones, token use and issue flow: [the journey](../journey/).\n\n## Tooling\n", $this->site('highlights/index.md'));
    }

    public function testTheFrontPageAndItsAssetsAreCopiedUnchanged(): void
    {
        $this->runPages();

        foreach ($this->frontPageFiles() as $path) {
            self::assertSame((string) file_get_contents(self::FRONT_PAGE.'/'.$path), $this->site($path), $path);
        }
    }

    public function testTheFrontPageGivesJekyllNothingToRender(): void
    {
        $page = (string) file_get_contents(self::FRONT_PAGE.'/index.html');

        self::assertStringStartsWith('<!doctype html>', $page, 'a page with front matter would get a layout and Liquid');
        self::assertDoesNotMatchRegularExpression('/\{[{%]/', $page);
    }

    public function testTheFrontPageLinksTheOtherPagesRelativelyAndHoldsEveryAssetItNames(): void
    {
        $page = (string) file_get_contents(self::FRONT_PAGE.'/index.html');
        preg_match_all('/(?:href|src)="(?!https:\/\/|#)([^"]*)"/', $page, $links);
        preg_match_all('/url\(([^)]+)\)/', (string) file_get_contents(self::FRONT_PAGE.'/assets/site.css'), $fonts);

        self::assertSame(['./', 'highlights/', 'journey/'], array_values(array_unique(array_filter($links[1], static fn (string $link): bool => !str_starts_with($link, 'assets/')))));

        foreach ([...array_filter($links[1], static fn (string $link): bool => str_starts_with($link, 'assets/')), ...array_map(static fn (string $font): string => 'assets/'.$font, $fonts[1])] as $asset) {
            self::assertFileExists(self::FRONT_PAGE.'/'.$asset);
        }
    }

    public function testEveryAssetOfTheFrontPageIsSmall(): void
    {
        foreach ($this->frontPageFiles() as $path) {
            self::assertLessThan(self::LARGEST_ASSET, filesize(self::FRONT_PAGE.'/'.$path), $path);
        }
    }

    public function testTheSiteHoldsOnlyThePagesAndTheirDrawings(): void
    {
        $this->runPages();

        $expected = [...$this->frontPageFiles(), 'highlights/index.md', 'journey/index.md', ...array_map(static fn (string $name): string => 'journey/'.$name.'.svg', self::DRAWINGS)];
        sort($expected);
        self::assertSame($expected, $this->siteFiles());
    }

    public function testASecondRunWithTheSameInputsChangesNothing(): void
    {
        $this->runPages();
        $first = array_map($this->site(...), $this->siteFiles());

        $second = $this->runPages();

        self::assertSame($first, array_map($this->site(...), $this->siteFiles()));
        self::assertStringEndsWith("site is current\n", $second->getOutput());
    }

    public function testAFailingJourneyRunExitsWithoutWritingTheSite(): void
    {
        $run = $this->runPages($this->root.'/no-recording');

        self::assertSame(1, $run->getExitCode());
        self::assertStringContainsString('no recorded answer issues.json', $run->getErrorOutput());
        self::assertSame([], $this->siteFiles());
    }

    public function testAFailingHighlightsRunExitsWithoutRunningTheJourney(): void
    {
        file_put_contents($this->root.'/docs/highlights.d/2-broken.md', "| Clock | done |\n");

        $run = $this->runPages();

        self::assertSame(1, $run->getExitCode());
        self::assertSame([], $this->siteFiles());
        self::assertSame([], glob($this->root.'/docs/journey/*.svg'));
    }

    public function testAMissingFolderIsAUsageError(): void
    {
        $run = new Process([PHP_BINARY, 'bin/pages', '--recorded=x'], $this->root);
        $run->run();

        self::assertSame(2, $run->getExitCode());
        self::assertSame("usage: bin/pages <folder> [--recorded=<folder with the recorded API answers>]\n", $run->getErrorOutput());
    }

    private function runPages(string $recorded = self::FIXTURES.'/api'): Process
    {
        $process = new Process([PHP_BINARY, 'bin/pages', 'site', '--recorded='.$recorded], $this->root);
        $process->run();

        return $process;
    }

    private function site(string $path): string
    {
        return (string) file_get_contents($this->root.'/site/'.$path);
    }

    /**
     * @return list<string>
     */
    private function siteFiles(): array
    {
        $paths = [...(array) glob($this->root.'/site/*.*'), ...(array) glob($this->root.'/site/assets/*'), ...(array) glob($this->root.'/site/highlights/*'), ...(array) glob($this->root.'/site/journey/*')];
        $files = array_map(fn (string $path): string => substr($path, strlen($this->root.'/site/')), array_filter($paths, is_string(...)));
        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function frontPageFiles(): array
    {
        return ['index.html', ...array_map(static fn (string $path): string => 'assets/'.basename($path), array_filter((array) glob(self::FRONT_PAGE.'/assets/*'), is_string(...)))];
    }

    private function expected(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.'/expected/'.$name.'.svg');
    }
}
