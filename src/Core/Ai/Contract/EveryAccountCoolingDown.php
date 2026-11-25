<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use RuntimeException;

// Thrown before any call is sent, so a provider whose every account is out costs no request.
final class EveryAccountCoolingDown extends RuntimeException
{
    public function __construct(
        Provider $provider,
        public readonly int $secondsUntilAnAccountIsFree,
    ) {
        parent::__construct(sprintf('Every account of provider "%s" is cooling down.', $provider->value));
    }
}
