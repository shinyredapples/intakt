<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class ReviewTextHoldsSecret extends RuntimeException implements ReportableFailure
{
    public static function afterRedaction(): self
    {
        return new self('The review text still trips the secret scan after redaction, so it is not posted.');
    }
}
