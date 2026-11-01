<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Vendor\Contract;

use DateTimeImmutable;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ProviderRateLimited::class)]
final class ProviderRateLimitedTest extends TestCase
{
    private const string NOW = '2026-10-27T12:00:00+00:00';

    public function testTheResetIsTheWaitTheProviderSentAfterNow(): void
    {
        $limited = new ProviderRateLimited(30, new RuntimeException('429'));

        self::assertSame(30, $limited->retryAfterSeconds);
        self::assertEquals(new DateTimeImmutable('2026-10-27T12:00:30+00:00'), $limited->resetsAt(new DateTimeImmutable(self::NOW)));
    }

    public function testARateLimitWithoutAResetHasNone(): void
    {
        $limited = new ProviderRateLimited(null, new RuntimeException('429'));

        self::assertNull($limited->retryAfterSeconds);
        self::assertNull($limited->resetsAt(new DateTimeImmutable(self::NOW)));
    }

    public function testANegativeWaitResetsNowInsteadOfInThePast(): void
    {
        $limited = new ProviderRateLimited(-5, new RuntimeException('429'));

        self::assertSame(0, $limited->retryAfterSeconds);
        self::assertEquals(new DateTimeImmutable(self::NOW), $limited->resetsAt(new DateTimeImmutable(self::NOW)));
    }

    public function testAHugeWaitIsCappedAtOneDay(): void
    {
        $limited = new ProviderRateLimited(PHP_INT_MAX, new RuntimeException('429'));

        self::assertSame(86400, $limited->retryAfterSeconds);
        self::assertEquals(new DateTimeImmutable('2026-10-28T12:00:00+00:00'), $limited->resetsAt(new DateTimeImmutable(self::NOW)));
    }

    public function testTheProviderFailureStaysTheCause(): void
    {
        $failure = new RuntimeException('429');

        self::assertSame($failure, new ProviderRateLimited(30, $failure)->getPrevious());
    }
}
