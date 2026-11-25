<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\ReviewSizeLimit;
use Intakt\Core\Review\Contract\ReviewSizeLimits;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewSizeLimits::class)]
#[CoversClass(ReviewSizeLimit::class)]
final class ReviewSizeLimitsTest extends TestCase
{
    public function testTheConfiguredLimitOfAProjectIsReadAsATypedValue(): void
    {
        $limit = new ReviewSizeLimits(['alpha' => 350000])->for('alpha');

        self::assertSame(350000, $limit->characters);
    }

    public function testAProjectMissingFromTheMapReadsTheDefaultLimit(): void
    {
        self::assertSame(200000, new ReviewSizeLimits([])->for('beta')->characters);
    }

    public function testALimitBelowOneCharacterIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReviewSizeLimit(0);
    }
}
