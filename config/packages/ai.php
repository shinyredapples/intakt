<?php

declare(strict_types=1);

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\ProjectModelCatalog;
use Intakt\Core\Ai\Infrastructure\ProjectsConfiguration;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Review\Infrastructure\PanelConfiguration;
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\EnvVarProcessor;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ReferenceConfigurator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Yaml\Yaml;

// Same sources as Symfony's env processor: the environment, then the secrets vault. Presence is decided at build time because an unset env reference fails when the service is built.
return static function (ContainerConfigurator $container): void {
    $environmentValue = static function (string $name): ?string {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && '' !== $value ? $value : null;
    };

    // The framework's default vault directory and decryption key (SYMFONY_DECRYPTION_SECRET when set, else the vault's own private key file), which config/packages/framework.yaml keeps.
    $vaultDirectory = __DIR__.'/../secrets/'.($environmentValue('APP_RUNTIME_ENV') ?? $container->env());
    // Decoded by the framework's own base64: env processor (lenient, either alphabet), so a key the framework accepts is not missing here.
    $decryptionSecret = $environmentValue('SYMFONY_DECRYPTION_SECRET') ?? '';
    $decryptionKey = new EnvVarProcessor(new Container())->getEnv('base64', 'SYMFONY_DECRYPTION_SECRET', static fn (): string => $decryptionSecret);
    $vault = new SodiumVault($vaultDirectory, is_string($decryptionKey) ? $decryptionKey : null);
    // Names only, since decrypting every entry up front would let an unrelated secret abort the boot; the framework's env loader reveals one name at a time too.
    $vaultNames = $vault->list();

    $vaultValue = static function (string $name) use ($vault, $vaultNames): ?string {
        if (!array_key_exists($name, $vaultNames)) {
            return null;
        }

        try {
            return $vault->reveal($name);
        } catch (SodiumException|RuntimeException) {
            // A decryption key of the wrong length, or no key at all, throws where a wrong key only returns null; either way the secret is unusable, so it counts as missing.
            return null;
        }
    };

    // Revealed because an empty secret is as unusable as a missing one; a secret that cannot be decrypted would fail when the service is built.
    $isSet = static fn (string $name): bool => null !== $environmentValue($name) || '' !== ($vaultValue($name) ?? '');

    // Projects are the owner's data per install, so their file stays out of git; a missing file means no projects rather than a failed boot.
    $projectsFile = $environmentValue('INTAKT_CONFIG') ?? __DIR__.'/../intakt.yaml';
    /** @var array<string, mixed> $contents */
    $contents = is_file($projectsFile) ? Yaml::parseFile($projectsFile) ?? [] : [];
    $contentProjects = $contents['projects'] ?? [];
    // Each module's tree refuses keys it does not know, so a project's review section goes to the review tree only; a projects value that is no map stays as written for the projects tree to refuse.
    $reviews = [];
    if (is_array($contentProjects)) {
        foreach ($contentProjects as $name => $project) {
            if (is_array($project) && array_key_exists('review', $project)) {
                $reviews[$name] = $project['review'];
                unset($project['review']);
                $contentProjects[$name] = $project;
            }
        }

        $contents['projects'] = $contentProjects;
    }

    /** @var array<string, array{tiers: array<string, array{provider: string, model: non-empty-string}>}> $projects */
    $projects = new Processor()->processConfiguration(new ProjectsConfiguration(), [$contents])['projects'];
    $tierProviders = array_map(static fn (array $project): array => array_map(static fn (array $tier): string => $tier['provider'], $project['tiers']), $projects);
    $panels = new Processor()->processConfiguration(new PanelConfiguration($tierProviders), [['projects' => $reviews]])['projects'];
    $catalog = new ProjectModelCatalog($projects);
    $modelCatalog = static fn (Provider $provider): array => ['models' => $catalog->models($provider)];

    $configs = [
        Provider::Anthropic->value => ['anthropic' => ['api_key' => '%env(ANTHROPIC_API_KEY)%', 'cache_retention' => 'short', 'model_catalog' => $modelCatalog(Provider::Anthropic)]],
        Provider::OpenAi->value => ['openai' => ['api_key' => '%env(OPENAI_API_KEY)%', 'model_catalog' => $modelCatalog(Provider::OpenAi)]],
        Provider::OpenAiCompatible->value => ['generic' => ['openai_compatible' => [
            'base_url' => '%env(OPENAI_COMPATIBLE_BASE_URL)%',
            'api_key' => '%env(OPENAI_COMPATIBLE_API_KEY)%',
            'model_catalog' => $modelCatalog(Provider::OpenAiCompatible),
        ]]],
    ];

    // Ids as the AI Bundle registers them, because a tier call reaches its provider through the bundle's own platform.
    $bundlePlatforms = [
        Provider::Anthropic->value => 'ai.platform.anthropic',
        Provider::OpenAi->value => 'ai.platform.openai',
        Provider::OpenAiCompatible->value => 'ai.platform.generic.openai_compatible',
    ];

    $platform = [];
    $disabled = [];
    $tierPlatforms = [];

    foreach (Provider::cases() as $provider) {
        $missing = array_values(array_filter($provider->variables(), static fn (string $variable): bool => !$isSet($variable)));

        if ([] !== $missing) {
            $disabled[$provider->value] = $provider->disabledMessage($missing);

            continue;
        }

        $platform = array_replace_recursive($platform, $configs[$provider->value]);
        $tierPlatforms[$provider->value] = new ReferenceConfigurator($bundlePlatforms[$provider->value]);
    }

    // The reviewer's rounds each keep a conversation, in the table the migration creates for the DBAL store.
    $conversations = ['doctrine' => ['dbal' => ['reviewer_conversation' => ['connection' => 'default']]]];

    $container->extension('ai', ['platform' => $platform, 'message_store' => $conversations]);
    $container->parameters()->set('intakt.ai.disabled_providers', $disabled);
    $container->parameters()->set('intakt.projects', $projects);
    $container->parameters()->set('intakt.review.panels', $panels);
    $container->services()->set(TierPlatform::PLATFORMS, ServiceLocator::class)
        ->args([$tierPlatforms])
        ->tag('container.service_locator');
};
