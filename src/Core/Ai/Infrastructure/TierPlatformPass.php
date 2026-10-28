<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

// The AI Bundle builds one platform per provider, while routing a tier needs every enabled provider in one platform; each provider is made from the bundle's own definition, so it keeps the key, HTTP client, contract and model_catalog the bundle configured.
final class TierPlatformPass implements CompilerPassInterface
{
    public const string PLATFORM = 'intakt.ai.platform';

    public function process(ContainerBuilder $container): void
    {
        $providers = [];

        foreach (Provider::cases() as $provider) {
            if ($container->hasDefinition($this->bundlePlatform($provider))) {
                $providers[] = $this->providerOf($container->getDefinition($this->bundlePlatform($provider)), $provider);
            }
        }

        // A platform refuses an empty provider list, and with every provider disabled there is nothing to route to.
        if ([] === $providers) {
            return;
        }

        // Public because no service injects it before the vendor plugin (plan T5), and an unused private service is removed.
        $container->register(self::PLATFORM, Platform::class)
            ->setArguments([$providers, new Reference(TierModelRouter::class), new Reference('event_dispatcher')])
            ->setPublic(true);
    }

    private function bundlePlatform(Provider $provider): string
    {
        return match ($provider) {
            Provider::Anthropic => 'ai.platform.anthropic',
            Provider::OpenAi => 'ai.platform.openai',
            Provider::OpenAiCompatible => 'ai.platform.generic.openai_compatible',
        };
    }

    // Each bridge factory's createProvider() takes the leading arguments of its createPlatform(), which the bundle fills.
    private function providerOf(Definition $platform, Provider $provider): Definition
    {
        /** @var array{0: class-string, 1: string} $factory */
        $factory = $platform->getFactory();

        return new Definition(ProviderInterface::class)
            ->setFactory([$factory[0], 'createProvider'])
            ->setArguments($platform->getArguments())
            ->setArgument('$name', $provider->value);
    }
}
