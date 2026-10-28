<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use NoDiscard;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ServiceProviderInterface;

// Public because no service injects it before the vendor plugin (plan T5), and an unused private service is removed.
#[Autoconfigure(public: true)]
final readonly class TierPlatform
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
    /**
     * @param array<mixed>|string|object $input
     */
    #[NoDiscard('the answer is only in the result')]
    public function invoke(string $project, Tier $tier, array|string|object $input): DeferredResult
    {
        $resolved = $this->resolver->resolve($project, $tier);

        return $this->platform($resolved->provider)->invoke($resolved->model, $input);
    }

    private function platform(Provider $provider): PlatformInterface
    {
        return $this->platforms->get($provider->value);
    }
}
