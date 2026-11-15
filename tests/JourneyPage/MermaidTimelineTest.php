<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use Iterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MermaidTimeline::class)]
#[CoversClass(Timeline::class)]
#[CoversClass(TimelineSection::class)]
#[CoversClass(TimelinePeriod::class)]
#[UsesClass(JourneyInputFailed::class)]
final class MermaidTimelineTest extends TestCase
{
    public function testTheTitleSectionsPeriodsAndEventsAreRead(): void
    {
        $timeline = new MermaidTimeline()->in($this->page(<<<'MERMAID'
                title Intakt, 2026-10-24 to the deadline
                section Design
                    10-24 : Repository created, name chosen : Second build slot on
                    10-26 : Design written
                          : First pull requests merged
                section Deadline
                    11-22 : Hackathon deadline 18:00 CET, repository public
            MERMAID));

        self::assertEquals(new Timeline('Intakt, 2026-10-24 to the deadline', [
            new TimelineSection('Design', [
                new TimelinePeriod('10-24', ['Repository created, name chosen', 'Second build slot on']),
                new TimelinePeriod('10-26', ['Design written', 'First pull requests merged']),
            ]),
            new TimelineSection('Deadline', [new TimelinePeriod('11-22', ['Hackathon deadline 18:00 CET, repository public'])]),
        ]), $timeline);
    }

    public function testATimelineWithoutATitleIsRead(): void
    {
        $timeline = new MermaidTimeline()->in($this->page("    section Design\n        10-24 : Repository created"));

        self::assertSame('', $timeline->title);
    }

    #[DataProvider('refusedPages')]
    public function testAPageWithoutExactlyOneTimelineBlockIsRefused(string $page): void
    {
        $this->expectException(JourneyInputFailed::class);
        $this->expectExceptionMessageMatches('/exactly one mermaid timeline block/');

        new MermaidTimeline()->in($page);
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function refusedPages(): Iterator
    {
        yield 'no block' => ["# Journey\n"];
        yield 'another mermaid diagram' => ["```mermaid\nflowchart LR\n    a --> b\n```\n"];
        yield 'two timelines' => ["```mermaid\ntimeline\n    section A\n```\n\n```mermaid\ntimeline\n    section B\n```\n"];
    }

    #[DataProvider('refusedLines')]
    public function testALineTheReaderDoesNotKnowIsRefused(string $lines, string $reason): void
    {
        $this->expectException(JourneyInputFailed::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($reason, '/').'/');

        new MermaidTimeline()->in($this->page($lines));
    }

    /**
     * @return Iterator<string, array{string, string}>
     */
    public static function refusedLines(): Iterator
    {
        yield 'a period before any section' => ['    10-24 : Repository created', 'timeline line 1 is refused: it comes before the first section'];
        yield 'a continuation before any period' => ["    section Design\n        : First pull requests merged", 'timeline line 2 is refused: it continues a period that does not exist'];
        yield 'a line without events' => ["    section Design\n        10-24", 'timeline line 2 is refused: it is not a title, a section or a period with its events'];
        yield 'a colon without spaces' => ["    section Design\n        10-24:Repository created", 'timeline line 2 is refused'];
    }

    private function page(string $lines): string
    {
        return "Before.\n\n```mermaid\ntimeline\n".$lines."\n```\n\nAfter.\n";
    }
}
