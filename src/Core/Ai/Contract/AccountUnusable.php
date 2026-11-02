<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use Symfony\AI\Platform\Exception\RuntimeException;
use Throwable;

// A platform exception, so a failover moves past the account like past any failed one; the message names only the error, never the vendor's text, which can carry anything.
final class AccountUnusable extends RuntimeException
{
    public function __construct(
        public readonly AccountError $error,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('The vendor refuses the account until the owner acts: %s.', $error->value), previous: $previous);
    }
}
