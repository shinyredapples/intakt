<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimelineSvg::class)]
#[UsesClass(ChartCanvas::class)]
#[UsesClass(ChartPalette::class)]
#[UsesClass(Timeline::class)]
#[UsesClass(TimelineSection::class)]
#[UsesClass(TimelinePeriod::class)]
final class TimelineSvgTest extends TestCase
{
    public function testEachEventGetsItsOwnLineBesideItsPeriod(): void
    {
        $svg = new TimelineSvg()->svg(new Timeline('Intakt', [new TimelineSection('Design', [new TimelinePeriod('10-24', ['Repository created', 'Second build slot on'])])]));

        self::assertStringContainsString('<text x="76" y="94" font-size="12" font-weight="600" fill="#0b0b0b">10-24</text>', $svg);
        self::assertStringContainsString('<text x="150" y="94" font-size="12" fill="#0b0b0b">Repository created</text>', $svg);
        self::assertStringContainsString('<text x="150" y="112" font-size="12" fill="#0b0b0b">Second build slot on</text>', $svg);
    }

    public function testTheDrawingGrowsWithTheEventsItHolds(): void
    {
        $short = new TimelineSvg()->svg(new Timeline('Intakt', [new TimelineSection('Design', [new TimelinePeriod('10-24', ['One'])])]));
        $long = new TimelineSvg()->svg(new Timeline('Intakt', [new TimelineSection('Design', [new TimelinePeriod('10-24', ['One', 'Two'])])]));

        self::assertStringContainsString('height="132"', $short);
        self::assertStringContainsString('height="150"', $long);
    }

    public function testTextFromThePageIsEscapedForXml(): void
    {
        $svg = new TimelineSvg()->svg(new Timeline('', [new TimelineSection('A & B', [new TimelinePeriod('<b>', ['"quoted" & more'])])]));

        self::assertStringContainsString('>A &amp; B</text>', $svg);
        self::assertStringContainsString('>&lt;b&gt;</text>', $svg);
        self::assertStringContainsString('>&quot;quoted&quot; &amp; more</text>', $svg);
        self::assertStringContainsString('aria-label="Timeline"', $svg);
    }
}
