<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use Intakt\Core\Ai\Contract\Provider;
use Uri\Rfc3986\Uri;

// The facts of one provider a key can belong to, so adding a provider or refreshing one is a data edit outside Core.
interface ProviderDescriptor
{
    /**
     * @return non-empty-string
     */
    public function id(): string;

    public function label(): string;

    // The first one holds the provider's key, so a kept key is served as that variable.
    /**
     * @return list<string>
     */
    public function variables(): array;

    // Anchored with the D modifier, so a key with a trailing newline does not fit.
    /**
     * @return non-empty-string
     */
    public function keyPattern(): string;

    public function baseUrl(): Uri;

    // The provider the project tiers name this provider's models under, because the check model defaults to that provider's light tier.
    public function tierProvider(): Provider;

    // Null takes the light tier's model (on a fresh install the sample project's), so no model id sits in PHP and a retired model is replaced in config.
    /**
     * @return non-empty-string|null
     */
    public function checkModel(): ?string;

    // The smallest answer cap the provider accepts, because a smaller one is refused as a bad request and would leave every key not checked.
    /**
     * @return positive-int
     */
    public function answerTokensAtLeast(): int;
}
