<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Infrastructure;

use InvalidArgumentException;

final class OwnerKeyRefused extends InvalidArgumentException
{
    public static function notAPublicKey(string $signer, int $position): self
    {
        // The key itself stays out of the message, because a refused value may be anything the owner pasted.
        return new self(sprintf('%s key %d is not one SSH public key ("<type> <base64>" with an optional plain comment).', ucfirst($signer), $position));
    }
}
