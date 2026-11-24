<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Labels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Labels::class)]
final class LabelsTest extends TestCase
{
    public function testLabelsContainALabelTheyWereGiven(): void
    {
        self::assertTrue(new Labels('priority:high', 'review:full')->contains('review:full'));
    }

    public function testLabelsDoNotContainALabelTheyWereNotGiven(): void
    {
        self::assertFalse(new Labels('review:security')->contains('review:full'));
    }

    public function testALabelMatchesOnlyItsExactSpelling(): void
    {
        self::assertFalse(new Labels('Review:Full', 'review:full ')->contains('review:full'));
    }

    public function testNoLabelsContainNothing(): void
    {
        self::assertFalse(new Labels()->contains('review:full'));
    }

    public function testTheLabelsIterateInTheOrderTheyWereGiven(): void
    {
        self::assertSame(['model:light', 'bug'], [...new Labels('model:light', 'bug')]);
    }
}
