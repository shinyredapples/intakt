<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use RuntimeException;

// The message is fixed and no previous exception is kept, because the refused write's arguments hold the key.
final class ProviderKeyAlreadyKept extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A provider key is already kept.');
    }
}
