<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use RuntimeException;
use Throwable;

final class ProviderRejectedTheKey extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('The provider rejected the account\'s key.', previous: $previous);
    }
}
