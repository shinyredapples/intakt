<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

interface TierResolver
{
    public function projects(): ProjectNames;

    /**
     * @throws TierResolutionFailure
     */
    public function resolve(string $project, Tier $tier): ResolvedModel;

    // The tier's own model also on a disabled provider, because a tier with substitutes counts a disabled provider as one with no free account.
    /**
     * @throws TierResolutionFailure
     */
    public function configured(string $project, Tier $tier): ResolvedModel;

    public function isDisabled(Provider $provider): bool;

    // In the order they are tried; one on a disabled provider is left out, because it could never answer.
    /**
     * @return list<ResolvedModel>
     *
     * @throws TierResolutionFailure
     */
    public function substitutes(string $project, Tier $tier): array;

    // Counts a substitute on a disabled provider too, because the owner named a way around a provider outage, so the tier ends blocked instead of waiting.
    /**
     * @throws TierResolutionFailure
     */
    public function hasSubstitutes(string $project, Tier $tier): bool;
}
