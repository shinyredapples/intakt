<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\KeepProviderKey;

use RuntimeException;

// The message is fixed and never carries the key or the provider's text, so a refused key cannot reach a log.
final class KeyRefusedByProvider extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The provider refused the key.');
    }
}
