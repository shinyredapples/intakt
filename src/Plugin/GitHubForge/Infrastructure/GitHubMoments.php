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

    /**
     * @throws ForgeReadFailed
     */
    public function parse(string $text): DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat(self::FORMAT, $text);

        return false === $moment ? throw new ForgeReadFailed('GitHub answered a moment this forge cannot read.') : $moment->setTimezone(new DateTimeZone('UTC'));
    }
}
