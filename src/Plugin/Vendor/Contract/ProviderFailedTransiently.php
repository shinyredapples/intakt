<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use RuntimeException;
use Throwable;

final class ProviderFailedTransiently extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('The provider failed in a way that a later retry may overcome.', previous: $previous);
    }
}
