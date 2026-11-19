<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\PinnedProvider;
use RuntimeException;

// Thrown instead of sending, because FailoverPlatform moves on to the next account after any exception.
final class PinnedProviderSkipped extends RuntimeException
{
    public function __construct(PinnedProvider $provider)
    {
        parent::__construct(sprintf('The pinned provider "%s" cools down, so the call was not sent.', $provider->key()));
    }
}
