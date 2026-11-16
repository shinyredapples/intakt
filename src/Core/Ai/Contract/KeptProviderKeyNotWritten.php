<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use RuntimeException;

// The message is fixed and no previous exception is kept: the failed write's arguments hold the key.
final class KeptProviderKeyNotWritten extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The provider key cannot be kept.');
    }
}
