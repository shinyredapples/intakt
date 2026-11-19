<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// Because a design rule that quotes copy the mock no longer shows describes a board nobody can see.
#[CoversNothing]
final class DashboardMockDesignCopyTest extends TestCase
{
    private const string MOCKS = '/docs/design/mocks';

    public function testTheFirstDayExampleQuotesTheInReviewLaneTheBoardShows(): void
    {
        $mocks = dirname(__DIR__, 2).self::MOCKS;
        $board = (string) file_get_contents($mocks.'/assets/intakt.js');
        $design = (string) file_get_contents($mocks.'/DESIGN.md');

        self::assertSame(1, preg_match('/"In review": "([^"]+)",/', $board, $lane));
        self::assertSame(1, preg_match('/^.*\*\*First day\*\*.*$/m', $design, $firstDay));
        self::assertStringContainsString('"'.($lane[1] ?? '').'"', $firstDay[0] ?? '');
    }
}
