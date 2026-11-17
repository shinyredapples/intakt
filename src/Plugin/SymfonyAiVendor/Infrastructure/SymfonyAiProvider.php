<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Plugin\Vendor\Contract\ProviderDescriptor;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Each provider knows the Symfony AI bridge that speaks its API, so the key check names no vendor.
interface SymfonyAiProvider extends ProviderDescriptor
{
    // All a one-word answer needs, so the check model is called even when the bridge's own catalog does not list it.
    public const array CHECK_CAPABILITIES = [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT];

    /**
     * @param non-empty-string $model
     */
    public function platform(string $secret, string $model, HttpClientInterface $http): PlatformInterface;
}
