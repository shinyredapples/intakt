<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

// Whether an account holds what its calls need, read when asked, because a Codex login is a file the owner copies in after the container is built.
interface AccountCredentials
{
    public function areSet(Account $account): bool;
}
