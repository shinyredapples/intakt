<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function is_string;
use function sprintf;

// Same lookup order as Symfony's env processor. Presence is decided at build time because an unset env reference fails when the service is built.
return static function (ContainerConfigurator $container): void {
    $isSet = static function (string $name): bool {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && '' !== $value;
    };

    $providers = [
        'anthropic' => [
            'needs' => ['ANTHROPIC_API_KEY'],
            'config' => ['anthropic' => ['api_key' => '%env(ANTHROPIC_API_KEY)%']],
        ],
        'openai' => [
            'needs' => ['OPENAI_API_KEY'],
            'config' => ['openai' => ['api_key' => '%env(OPENAI_API_KEY)%']],
        ],
        'openai_compatible' => [
            'needs' => ['OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY'],
            'config' => ['generic' => ['openai_compatible' => [
                'base_url' => '%env(OPENAI_COMPATIBLE_BASE_URL)%',
                'api_key' => '%env(OPENAI_COMPATIBLE_API_KEY)%',
            ]]],
        ],
    ];

    $platform = [];
    $disabled = [];

    foreach ($providers as $name => $provider) {
        $missing = array_values(array_filter($provider['needs'], static fn (string $variable): bool => !$isSet($variable)));

        if ([] !== $missing) {
            $disabled[$name] = sprintf('Provider "%s" is disabled: set %s to enable it.', $name, implode(' and ', $missing));

            continue;
        }

        $platform = array_replace_recursive($platform, $provider['config']);
    }

    $container->extension('ai', ['platform' => $platform]);
    $container->parameters()->set('intakt.ai.disabled_providers', $disabled);
};
