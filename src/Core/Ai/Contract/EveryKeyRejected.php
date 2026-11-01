<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use Symfony\AI\Platform\Exception\AuthenticationException;

// An AuthenticationException, so it ends a reviewer failed like the provider's own rejection; the message names only the provider, never the provider's error text, which can echo a key.
final class EveryKeyRejected extends AuthenticationException
{
    public function __construct(Provider $provider)
    {
        parent::__construct(sprintf('Every key of provider "%s" was rejected.', $provider->value));
    }
}
