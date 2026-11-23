<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use NoDiscard;

// An interface, because the build task checks what its job hands back and other modules may use only this module's contract.
interface HandBackChecks
{
    /**
     * @throws HandBackBranchRefused
     * @throws HandBackNotChecked
     * @throws RepositoryAttributesRefused
     */
    #[NoDiscard]
    public function check(HandBack $handBack): AcceptedHandBack|RefusedHandBack;
}
