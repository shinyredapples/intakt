<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonotonicSeconds::class)]
final class MonotonicSecondsTest extends TestCase
{
    public function testALaterReadingIsNeverEarlier(): void
    {
        $first = MonotonicSeconds::now();

        usleep(1000);

        self::assertGreaterThan($first, MonotonicSeconds::now());
    }
}
