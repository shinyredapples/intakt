<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use Intakt\Core\Ai\Contract\Provider;
use NoDiscard;

// Per provider, because a call runs on the first account outside cooldown and fails over to the next, so shadow work starts only while every account it may reach leaves the capacity the existing loop needs.
interface ShadowStartGuard
{
    #[NoDiscard]
    public function decide(Provider $provider): StartDecision;
}
