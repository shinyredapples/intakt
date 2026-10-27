<?php

declare(strict_types=1);

use Intakt\Core\Ai\Contract\Provider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Same lookup order as Symfony's env processor. Presence is decided at build time because an unset env reference fails when the service is built.
return static function (ContainerConfigurator $container): void {
    $isSet = static function (string $name): bool {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return \is_string($value) && '' !== $value;
    };

    $configs = [
        Provider::Anthropic->value => ['anthropic' => ['api_key' => '%env(ANTHROPIC_API_KEY)%']],
        Provider::OpenAi->value => ['openai' => ['api_key' => '%env(OPENAI_API_KEY)%']],
        Provider::OpenAiCompatible->value => ['generic' => ['openai_compatible' => [
            'base_url' => '%env(OPENAI_COMPATIBLE_BASE_URL)%',
            'api_key' => '%env(OPENAI_COMPATIBLE_API_KEY)%',
        ]]],
    ];

    $platform = [];
    $disabled = [];

    foreach (Provider::cases() as $provider) {
        $missing = array_values(array_filter($provider->variables(), static fn (string $variable): bool => !$isSet($variable)));

        if ([] !== $missing) {
            $disabled[$provider->value] = $provider->disabledMessage($missing);

            continue;
        }

        $platform = array_replace_recursive($platform, $configs[$provider->value]);
    }

    $container->extension('ai', ['platform' => $platform]);
    $container->parameters()->set('intakt.ai.disabled_providers', $disabled);
};
