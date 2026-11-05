<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// Its own type, chained under the read or write failure, because a refused credential fails every item alike and must start the install-wide failure back-off, unlike a rate limit or a broken call.
final class ForgeCredentialsRefused extends RuntimeException implements ReportableFailure
{
    public function __construct(int $status)
    {
        parent::__construct(sprintf('The forge refused Intakt\'s credentials with status %d.', $status));
    }
}
