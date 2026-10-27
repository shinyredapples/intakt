<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;

// Parses in UTC because the column has no offset and PHP's default zone would move a moment inside a daylight-saving gap.
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
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

        if (!is_string($value)) {
            throw InvalidType::new($value, self::class, ['null', 'string', DateTimeImmutable::class]);
        }

        $moment = DateTimeImmutable::createFromFormat('!'.$platform->getDateTimeFormatString(), $value, new DateTimeZone('UTC'));

        // PHP rolls a date that does not exist over into another one and reports it only as a warning.
        if (false === $moment || false !== DateTimeImmutable::getLastErrors()) {
            throw InvalidFormat::new($value, self::class, $platform->getDateTimeFormatString());
        }

        return $moment;
    }
}
