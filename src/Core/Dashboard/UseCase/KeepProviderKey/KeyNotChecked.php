<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\KeepProviderKey;

use RuntimeException;

// The message is fixed and never carries the key or the provider's text, so an unchecked key cannot reach a log.
final class KeyNotChecked extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The provider could not be asked to check the key.');
    }
}
