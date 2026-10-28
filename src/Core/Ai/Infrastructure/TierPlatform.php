<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Contract\TierResolver;
use NoDiscard;
use Override;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ServiceProviderInterface;

final readonly class TierPlatform implements TierInvoker
{
    public const string PLATFORMS = 'intakt.ai.provider_platforms';

    /**
     * @param ServiceProviderInterface<PlatformInterface> $platforms
     */
    public function __construct(
        private TierResolver $resolver,
        #[Autowire(service: self::PLATFORMS)]
        private ServiceProviderInterface $platforms,
    ) {
    }

    // Invoked on the provider's own bundle platform because its catalog-based model router and model_catalog already know the configured model.
    #[Override]
    #[NoDiscard('the answer is only in the result')]
    public function invoke(string $project, Tier $tier, TierRequest $request): DeferredResult
    {
        $resolved = $this->resolver->resolve($project, $tier);

        return $this->platform($resolved->provider)->invoke($resolved->model, $request->input, $request->options);
    }

    private function platform(Provider $provider): PlatformInterface
    {
        return $this->platforms->get($provider->value);
    }
}
