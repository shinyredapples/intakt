<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarkedRegions::class)]
#[UsesClass(PageRegion::class)]
#[UsesClass(JourneyInputFailed::class)]
final class MarkedRegionsTest extends TestCase
{
    private const string PAGE = "Intro\n\n<!-- journey:begin glance -->\nold\n<!-- journey:end glance -->\n\nStory.\n\n<!-- journey:begin chart -->\nkeep\n<!-- journey:end chart -->\nEnd\n";

    public function testOnlyTheLinesBetweenTheMarkersChange(): void
    {
        $page = new MarkedRegions()->replaced(self::PAGE, [new PageRegion('glance', 'new')]);

        self::assertSame(str_replace("\nold\n", "\nnew\n", self::PAGE), $page);
    }

    public function testAPageWithoutTheMarkersOfARegionIsRefused(): void
    {
        $this->expectException(JourneyInputFailed::class);
        $this->expectExceptionMessageMatches('/'.preg_quote('exactly one begin and one end marker for milestones', '/').'/');

        new MarkedRegions()->replaced(self::PAGE, [new PageRegion('milestones', 'x')]);
    }

    public function testADuplicatedMarkerIsRefused(): void
    {
        $this->expectException(JourneyInputFailed::class);

        new MarkedRegions()->replaced(self::PAGE.self::PAGE, [new PageRegion('glance', 'x')]);
    }

    public function testAnEndMarkerBeforeItsBeginMarkerIsRefused(): void
    {
        $this->expectException(JourneyInputFailed::class);
        $this->expectExceptionMessageMatches('/'.preg_quote('comes before', '/').'/');

        new MarkedRegions()->replaced("<!-- journey:end glance -->\n<!-- journey:begin glance -->\n", [new PageRegion('glance', 'x')]);
    }
}
