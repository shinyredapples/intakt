<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// Never git's own message, because it may quote a value from the bundle.
final class HandBackNotChecked extends RuntimeException implements ReportableFailure
{
    public static function in(string $repository, string $reason): self
    {
        return new self(sprintf('the hand-back could not be checked in %s: %s', $repository, $reason));
    }
}
