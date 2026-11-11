<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;

final readonly class RecordedMoment
{
    // GitHub's own moment format, with an offset (`Z` for UTC), because a moment without one could be any hour.
    public const string FORMAT = 'Y-m-d\TH:i:sp';

    public static function parse(string $text): ?DateTimeImmutable
    {
        // PHP throws on a null byte instead of refusing the text, which would skip the error that names the recorded file.
        if (str_contains($text, "\0")) {
            return null;
        }

        $moment = DateTimeImmutable::createFromFormat('!'.self::FORMAT, $text, new DateTimeZone('UTC'));

        // Refused on a warning, because PHP rolls an impossible date such as February 30 over into another day.
        if (false === $moment || false !== DateTimeImmutable::getLastErrors()) {
            return null;
        }

        return $moment->setTimezone(new DateTimeZone('UTC'));
    }
}
