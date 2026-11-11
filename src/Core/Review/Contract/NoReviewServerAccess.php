<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use RuntimeException;
use Throwable;

final class NoReviewServerAccess extends RuntimeException
{
    public static function because(Throwable $reason): self
    {
        return new self(sprintf('No access to the review server can be issued: %s', $reason->getMessage()), 0, $reason);
    }
}
