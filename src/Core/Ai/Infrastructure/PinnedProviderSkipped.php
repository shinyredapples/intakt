<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\PinnedProvider;
use RuntimeException;

// Thrown to AccountFailoverPlatform's FailoverPlatform only, which moves on to the next account; the message holds the account key and the router provider from the owner's model id, never a key.
final class PinnedProviderSkipped extends RuntimeException
{
    public function __construct(PinnedProvider $provider)
    {
        parent::__construct(sprintf('The pinned provider "%s" cools down, so the call was not sent.', $provider->key()));
    }
}
