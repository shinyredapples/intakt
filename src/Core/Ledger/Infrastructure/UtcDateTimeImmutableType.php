<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;

// The column holds a wall clock without an offset; parsing it in PHP's default zone moves a moment inside a daylight-saving gap.
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    public const string NAME = 'utc_datetime_immutable';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            return parent::convertToDatabaseValue($value->setTimezone(new DateTimeZone('UTC')), $platform);
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if (null === $value || $value instanceof DateTimeImmutable) {
            return $value;
        }

        $moment = DateTimeImmutable::createFromFormat('!'.$platform->getDateTimeFormatString(), $value, new DateTimeZone('UTC'));

        if (false === $moment) {
            throw InvalidFormat::new($value, self::class, $platform->getDateTimeFormatString());
        }

        return $moment;
    }
}
