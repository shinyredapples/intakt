<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use Intakt\Core\Ai\Contract\ProviderKey;
use NoDiscard;

interface KeyCheck
{
    // One cheap call with the key itself; a provider failure is an outcome, never an exception, because its text can echo the key.
    #[NoDiscard('a key whose check is not read is neither accepted nor refused')]
    public function check(ProviderKey $key): KeyCheckOutcome;
}
