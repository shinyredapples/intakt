<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mercure\Update;

#[CoversClass(StreamedPage::class)]
final class StreamedPageTest extends TestCase
{
    private const string TOPIC = 'review/rounds/round-1';

    private const string PAGE = <<<'HTML'
        <html><body>
        <p id="state">0 of 2 reviewers finished.</p>
        <ul id="log"><li>read_file</li></ul>
        <div id="judge"><p>Not judged.</p></div>
        <span id="idle"></span>
        <table><tbody><tr id="row"><td>running</td></tr></tbody></table>
        </body></html>
        HTML;

    public function testAReplaceStreamPutsItsTemplateWhereTheTargetWas(): void
    {
        $page = $this->streamed(['<turbo-stream action="replace" target="state"><template><p id="state">Judged.</p></template></turbo-stream>']);

        self::assertSame(['Judged.'], $page->filter('#state')->each(static fn (Crawler $state): string => $state->text()));
    }

    public function testAReplacedTableRowKeepsItsCells(): void
    {
        $page = $this->streamed(['<turbo-stream action="replace" target="row"><template><tr id="row"><td>stopped</td><td></td></tr></template></turbo-stream>']);

        self::assertCount(2, $page->filter('#row td'));
    }

    public function testAnAppendStreamAddsItsTemplateAfterTheTargetsChildren(): void
    {
        $page = $this->streamed(['<turbo-stream action="append" target="log"><template><li>grep</li></template></turbo-stream>']);

        self::assertSame(['read_file', 'grep'], $page->filter('#log li')->each(static fn (Crawler $item): string => $item->text()));
    }

    public function testAnUpdateStreamReplacesOnlyTheTargetsChildren(): void
    {
        $page = $this->streamed(['<turbo-stream action="update" target="judge"><template><p>Judged.</p></template></turbo-stream>']);

        self::assertSame('<p>Judged.</p>', $page->filter('#judge')->html());
    }

    public function testARemoveStreamTakesTheTargetAway(): void
    {
        $page = $this->streamed(['<turbo-stream action="remove" target="idle"></turbo-stream>']);

        self::assertCount(0, $page->filter('#idle'));
    }

    public function testAStreamWhoseTargetIsAbsentChangesNothing(): void
    {
        $page = $this->streamed(['<turbo-stream action="replace" target="missing"><template><p id="missing">New.</p></template></turbo-stream>']);

        self::assertCount(0, $page->filter('#missing'));
    }

    public function testAnUpdateOnAnotherTopicChangesNothing(): void
    {
        $page = new StreamedPage()->after(new Crawler(self::PAGE), self::TOPIC, [new Update('review/rounds/round-2', '<turbo-stream action="replace" target="state"><template><p id="state">Judged.</p></template></turbo-stream>')]);

        self::assertSame('0 of 2 reviewers finished.', $page->filter('#state')->text());
    }

    /**
     * @param list<string> $streams
     */
    private function streamed(array $streams): Crawler
    {
        return new StreamedPage()->after(new Crawler(self::PAGE), self::TOPIC, array_map(static fn (string $data): Update => new Update(self::TOPIC, $data), $streams));
    }
}
