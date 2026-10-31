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

    /**
     * @return list<ResolvedModel> the tier's configured substitutes in the order they are tried, those on a disabled provider left out
     *
     * @throws TierResolutionFailure
     */
    public function substitutes(string $project, Tier $tier): array;
}
