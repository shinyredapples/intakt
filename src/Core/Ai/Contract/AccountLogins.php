<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

interface AccountLogins
{
    /**
     * @throws EveryAccountCoolingDown
     * @throws EveryKeyRejected
     */
    // The first free account in the owner's order, as a call in the app's own process takes it.
    public function claudeCode(): AccountLogin;
}
