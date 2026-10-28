<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\ConversionException;
use Intakt\Core\Ledger\Infrastructure\UtcDateTimeImmutableType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UtcDateTimeImmutableType::class)]
final class UtcDateTimeImmutableTypeTest extends TestCase
{
    public function testAMomentInAnotherZoneIsStoredAsItsUtcWallClock(): void
    {
        $stored = new UtcDateTimeImmutableType()->convertToDatabaseValue(new DateTimeImmutable('2026-10-27 14:00:00+02:00'), new SQLitePlatform());

        self::assertSame('2026-10-27 12:00:00', $stored);
    }

    public function testAStoredWallClockIsReadAsUtcWhateverTheDefaultTimeZone(): void
    {
        $defaultTimeZone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            $moment = new UtcDateTimeImmutableType()->convertToPHPValue('2026-03-29 02:30:00', new SQLitePlatform());
        } finally {
            date_default_timezone_set($defaultTimeZone);
        }

        self::assertSame('2026-03-29T02:30:00+00:00', $moment->format('c'));
    }

    public function testAStoredValueThatIsNotAWallClockIsRefused(): void
    {
        $this->expectException(ConversionException::class);

        new UtcDateTimeImmutableType()->convertToPHPValue('yesterday', new SQLitePlatform());
    }

    // PHP would otherwise roll this over to 2026-03-03 01:00:00 and change the stored moment.
    public function testAStoredWallClockThatDoesNotExistIsRefused(): void
    {
        $this->expectException(ConversionException::class);

        new UtcDateTimeImmutableType()->convertToPHPValue('2026-02-30 25:00:00', new SQLitePlatform());
    }
}
