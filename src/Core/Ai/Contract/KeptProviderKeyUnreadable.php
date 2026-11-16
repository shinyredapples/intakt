<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use RuntimeException;

// The message is fixed: the file holds a key, so nothing of its content may reach a message or a log.
final class KeptProviderKeyUnreadable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The kept provider key cannot be read.');
    }
}
