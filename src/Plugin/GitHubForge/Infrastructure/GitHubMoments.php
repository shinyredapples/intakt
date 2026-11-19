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
    // An answer's Date header is an HTTP date, always in GMT, not the ISO moment of a JSON field.
    private const string HTTP_DATE = 'D, d M Y H:i:s \G\M\T';

    /**
     * @throws ForgeReadFailed
     */
    public function parse(string $text): DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat(self::FORMAT, $text, new DateTimeZone('UTC'));

        return false === $moment ? throw new ForgeReadFailed('GitHub answered a moment this forge cannot read.') : $moment->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @throws ForgeReadFailed
     */
    public function served(string $date): DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat(self::HTTP_DATE, $date, new DateTimeZone('UTC'));

        return false === $moment ? throw new ForgeReadFailed('GitHub answered a Date this forge cannot read.') : $moment->setTimezone(new DateTimeZone('UTC'));
    }
}
