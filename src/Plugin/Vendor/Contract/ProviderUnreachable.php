<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use RuntimeException;
use Throwable;

final class ProviderUnreachable extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('The model endpoint could not be reached.', previous: $previous);
    }
}
