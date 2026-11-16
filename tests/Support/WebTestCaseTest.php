<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Clock\ClockInterface;

#[CoversClass(WebTestCase::class)]
final class WebTestCaseTest extends WebTestCase
{
    public function testAClockThatWasCreatedBeforeIsStillMovedToTheMomentTheTestSets(): void
    {
        self::createClient();
        self::getContainer()->get(ClockInterface::class);

        self::clockAt('2026-10-28T12:00:00+00:00');

        self::assertSame('2026-10-28T12:00:00+00:00', self::getContainer()->get(ClockInterface::class)->now()->format('c'));
    }

    public function testMovingTheClockTwiceKeepsTheLaterMoment(): void
    {
        self::createClient();

        self::clockAt('2026-10-28T12:00:00+00:00');
        self::clockAt('2026-10-30T12:00:00+00:00');

        self::assertSame('2026-10-30T12:00:00+00:00', self::getContainer()->get(ClockInterface::class)->now()->format('c'));
    }
}
