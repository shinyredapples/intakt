<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use InvalidArgumentException;
use NoDiscard;

// The check of a bundle a build handed back, for the build lane, which may use only this module's contract.
interface HandBackChecks
{
    /**
     * @throws InvalidArgumentException
     * @throws HandBackNotChecked
     * @throws RepositoryAttributesRefused
     */
    #[NoDiscard]
    public function check(HandBack $handBack): AcceptedHandBack|RefusedHandBack;
}
