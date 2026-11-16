<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\ProviderKey;
use Symfony\AI\Platform\PlatformInterface;

interface KeyedPlatforms
{
    public function platformFor(ProviderKey $key): PlatformInterface;
}
