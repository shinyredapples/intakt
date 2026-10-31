<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

interface TierResolver
{
    /**
     * @return list<string>
     */
    public function projects(): array;

    /**
     * @throws TierResolutionFailure
     */
    public function resolve(string $project, Tier $tier): ResolvedModel;

    // In the order they are tried; one on a disabled provider is left out, because it could never answer.
    /**
     * @return list<ResolvedModel>
     *
     * @throws TierResolutionFailure
     */
    public function substitutes(string $project, Tier $tier): array;
}
