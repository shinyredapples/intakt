<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Contract\TierResolver;
use NoDiscard;
use Override;
use Symfony\AI\Platform\Result\DeferredResult;

final readonly class TierPlatform implements TierInvoker
{
    public const string PLATFORMS = 'intakt.ai.provider_platforms';

    public function __construct(
        private TierResolver $resolver,
        private ProviderFailoverPlatform $providers,
    ) {
    }

    // Invoked on the provider's own bundle platform because its catalog-based model router and model_catalog already know the configured model.
    #[Override]
    #[NoDiscard('the answer is only in the result')]
    public function invoke(string $project, Tier $tier, TierRequest $request): DeferredResult
    {
        $own = $this->resolver->resolve($project, $tier);

        return $this->providers->invoke($own, $this->resolver->substitutes($project, $tier), $request);
    }
}
