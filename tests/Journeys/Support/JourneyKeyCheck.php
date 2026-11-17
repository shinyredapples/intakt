<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Core\Ai\Contract\ProviderKey;
use Intakt\Plugin\Vendor\Contract\KeyCheck;
use Intakt\Plugin\Vendor\Contract\KeyCheckOutcome;

// Accepts every key without a call, so a journey can paste a placeholder key into the first-run page and no provider is asked.
final readonly class JourneyKeyCheck implements KeyCheck
{
    public function check(ProviderKey $key): KeyCheckOutcome
    {
        return KeyCheckOutcome::Accepted;
    }
}
