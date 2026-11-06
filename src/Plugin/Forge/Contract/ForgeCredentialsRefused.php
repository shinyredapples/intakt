<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use RuntimeException;

// A cause chained as previous, never thrown alone, so callers keep catching ForgeReadFailed and ForgeWriteFailed; the fixed message holds no token or key.
final class ForgeCredentialsRefused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The forge refused the credentials it was given.');
    }
}
