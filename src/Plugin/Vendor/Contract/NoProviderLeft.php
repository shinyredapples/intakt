<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use RuntimeException;
use Throwable;

// Every provider the tier may use is out, its substitutes too, so the reviewer ends blocked with the reason instead of waiting for a provider the owner has already named a way around.
final class NoProviderLeft extends RuntimeException
{
    public function __construct(string $reason, Throwable $previous)
    {
        parent::__construct($reason, previous: $previous);
    }
}
