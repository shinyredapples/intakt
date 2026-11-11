<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;

final readonly class RecordedMoment
{
    // GitHub's own moment format, with an offset (`Z` for UTC), because a moment without one could be any hour.
    private const string FORMAT = '!Y-m-d\TH:i:sp';

    public static function parse(string $text): ?DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat(self::FORMAT, $text, new DateTimeZone('UTC'));

        // A warning means PHP rolled an impossible date such as February 30 over into another day.
        if (false === $moment || false !== DateTimeImmutable::getLastErrors()) {
            return null;
        }

        return $moment->setTimezone(new DateTimeZone('UTC'));
    }
}
