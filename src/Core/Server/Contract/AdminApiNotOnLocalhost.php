<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class AdminApiNotOnLocalhost extends RuntimeException implements ReportableFailure
{
    public static function becauseItIsNotAUrl(): self
    {
        return new self('FRANKENPHP_ADMIN_URL is not a URL.');
    }

    public static function becauseOfTheAddress(string $scheme, string $host): self
    {
        return new self(sprintf('FRANKENPHP_ADMIN_URL must be plain http on localhost, 127.0.0.1 or [::1], not %s on %s.', $scheme, $host));
    }
}
