<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use RuntimeException;

// Thrown instead of returning, because a cooldown the cache refused would let the next call reach the provider at once.
final class PinnedProviderCooldownNotSaved extends RuntimeException
{
    public function __construct(PinnedProvider $provider)
    {
        parent::__construct(sprintf('The cooldown of the pinned provider "%s" cannot be saved.', $provider->key()));
    }
}
