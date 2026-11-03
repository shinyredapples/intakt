<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use DateTimeImmutable;
use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// Not a ForgeReadFailed, because the caller must wait for the reset instead of treating the call as broken.
final class ForgeRateLimited extends RuntimeException implements ReportableFailure
{
    public function __construct(public readonly DateTimeImmutable $resetsAt)
    {
        parent::__construct(sprintf('The forge is rate limited until %s.', $resetsAt->format(DATE_ATOM)));
    }
}
