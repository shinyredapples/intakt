<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;

final readonly class GitHubMoments
{
    // An explicit format, because GitHub always sends a moment with an offset (`Z` for UTC) and anything else is not its answer.
    private const string FORMAT = 'Y-m-d\TH:i:sp';
    // Apart from FORMAT, because an answer's Date header is an HTTP date in GMT, not the ISO moment of a JSON field.
    private const string HTTP_DATE = 'D, d M Y H:i:s \G\M\T';

    /**
     * @throws ForgeReadFailed
     */
    public function parse(string $text): DateTimeImmutable
    {
        return $this->strictly(self::FORMAT, $text) ?? throw new ForgeReadFailed('GitHub answered a moment this forge cannot read.');
    }

    /**
     * @throws ForgeReadFailed
     */
    public function served(string $date): DateTimeImmutable
    {
        return $this->strictly(self::HTTP_DATE, $date) ?? throw new ForgeReadFailed('GitHub answered a Date this forge cannot read.');
    }

    // PHP rolls an out-of-range field over with only a warning (second 99 becomes the next minute) and reads a weekday that does not match its day as the next such weekday without one, so only a moment that formats back to the received text is the moment GitHub sent.
    private function strictly(string $format, string $text): ?DateTimeImmutable
    {
        // Refused before parsing, because PHP raises a ValueError for a NUL byte instead of answering false.
        if (str_contains($text, "\0")) {
            return null;
        }

        $moment = DateTimeImmutable::createFromFormat($format, $text, new DateTimeZone('UTC'));

        return false === $moment || $moment->format($format) !== $text ? null : $moment->setTimezone(new DateTimeZone('UTC'));
    }
}
