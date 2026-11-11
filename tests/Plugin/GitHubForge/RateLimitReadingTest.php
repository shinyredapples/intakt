<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use DateTimeImmutable;
use Intakt\Plugin\GitHubForge\Domain\RateLimitReading;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimitReading::class)]
final class RateLimitReadingTest extends TestCase
{
    public function testOneCallLessThanTheShareIsBelowIt(): void
    {
        self::assertTrue($this->reading(remaining: 999, limit: 5000)->isBelowShare(20));
    }

    public function testExactlyTheShareIsNotBelowIt(): void
    {
        self::assertFalse($this->reading(remaining: 1000, limit: 5000)->isBelowShare(20));
    }

    public function testAShareOfALimitThatIsNoMultipleOfAHundredIsNotRounded(): void
    {
        self::assertTrue($this->reading(remaining: 1000, limit: 5001)->isBelowShare(20));
    }

    public function testNothingIsBelowAShareOfNothing(): void
    {
        self::assertFalse($this->reading(remaining: 0, limit: 5000)->isBelowShare(0));
    }

    private function reading(int $remaining, int $limit): RateLimitReading
    {
        return new RateLimitReading($remaining, $limit, new DateTimeImmutable('2026-11-25 10:30:00 UTC'));
    }
}
