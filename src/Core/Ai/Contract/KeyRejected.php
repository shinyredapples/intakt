<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use Symfony\AI\Platform\Exception\AuthenticationException;

// An AuthenticationException, so it ends a reviewer failed like the provider's own rejection; the message names only the account, never the provider's error text, which can echo a key.
final class KeyRejected extends AuthenticationException
{
    public function __construct(Account $account)
    {
        parent::__construct(sprintf('The key of account "%s" was rejected.', $account->key()));
    }
}
