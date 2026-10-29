<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use RuntimeException;
use Throwable;

final class UsageNotRecorded extends RuntimeException
{
    public function __construct(string $usage, string $reason, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Token usage was not recorded (%s): %s', $reason, $usage), previous: $previous);
    }
}
