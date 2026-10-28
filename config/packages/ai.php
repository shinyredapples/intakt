<?php

declare(strict_types=1);

use Intakt\Core\Ai\Contract\Provider;
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Same sources as Symfony's env processor: the environment, then the secrets vault. Presence is decided at build time because an unset env reference fails when the service is built.
return static function (ContainerConfigurator $container): void {
    $environmentValue = static function (string $name): ?string {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && '' !== $value ? $value : null;
    };

    // The framework's default vault directory and decryption key (the vault's own private key file, else SYMFONY_DECRYPTION_SECRET), which config/packages/framework.yaml keeps.
    $vaultDirectory = __DIR__.'/../secrets/'.($environmentValue('APP_RUNTIME_ENV') ?? $container->env());
    $decryptionKey = base64_decode($environmentValue('SYMFONY_DECRYPTION_SECRET') ?? '', true);
    // Revealed because an empty secret is as unusable as a missing one; a secret that cannot be decrypted would fail when the service is built.
    $secrets = new SodiumVault($vaultDirectory, false === $decryptionKey ? null : $decryptionKey)->list(reveal: true);

    $isSet = static fn (string $name): bool => null !== $environmentValue($name) || '' !== ($secrets[$name] ?? '');

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
