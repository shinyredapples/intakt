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
}
