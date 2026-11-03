<?php

declare(strict_types=1);

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\ClaudeCodePlatformFactory;
use Intakt\Core\Ai\Infrastructure\CodexLogins;
use Intakt\Core\Ai\Infrastructure\CodexPlatformFactory;
use Intakt\Core\Ai\Infrastructure\CodexSettings;
use Intakt\Core\Ai\Infrastructure\IsolatedClaudeCodeCli;
use Intakt\Core\Ai\Infrastructure\IsolatedCodexCli;
use Intakt\Core\Ai\Infrastructure\ProjectModelCatalog;
use Intakt\Core\Ai\Infrastructure\ProjectsConfiguration;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Budget\Infrastructure\CeilingsConfiguration;
use Intakt\Core\Memory\Infrastructure\EmbeddingsNotConfigured;
use Intakt\Core\Review\Infrastructure\PanelConfiguration;
use Intakt\Core\Review\Infrastructure\PlatformChosenPerReview;
use Intakt\Core\Review\Infrastructure\ReviewerMcpToolboxFactory;
use Intakt\Core\Shadow\Infrastructure\ProjectModeConfiguration;
use Intakt\Core\Watcher\Infrastructure\WatchConfiguration;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\ModelCatalog\CompositeModelCatalog;
use Symfony\AI\Platform\ModelCatalog\InMemoryModelCatalog;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Secrets\SodiumVault;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\EnvVarProcessor;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ReferenceConfigurator;
use Symfony\Component\DependencyInjection\Reference;
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
    // Each module's tree refuses keys it does not know, so a project's review section goes to the review tree only and its mode to the shadow tree only; a projects value that is no map stays as written for the projects tree to refuse.
    $reviews = [];
    $modes = [];
    $watches = [];
    if (is_array($contentProjects)) {
        foreach ($contentProjects as $name => $project) {
            if (is_array($project) && array_key_exists('review', $project)) {
                $reviews[$name] = $project['review'];
                unset($project['review']);
                $contentProjects[$name] = $project;
            }

            if (is_array($project) && array_key_exists('watch', $project)) {
                $watches[$name] = $project['watch'];
                unset($project['watch']);
                $contentProjects[$name] = $project;
            }

            if (is_array($project) && array_key_exists('mode', $project)) {
                $modes[$name] = ['mode' => $project['mode']];
                unset($project['mode']);
                $contentProjects[$name] = $project;
            }
        }

        $contents['projects'] = $contentProjects;
    }

    // The ceilings are the owner's for every account, so they sit beside the projects and go to the budget tree only.
    $budget = array_key_exists('budget', $contents) ? ['budget' => $contents['budget']] : [];
    unset($contents['budget']);
    /** @var array{budget: array{ceilings: array<string, float>, shadow_ceilings: array<string, float>}} $ceilings */
    $ceilings = new Processor()->processConfiguration(new CeilingsConfiguration(), [$budget]);

    /** @var array{projects: array<string, array{tiers: array<string, array{provider: string, model: non-empty-string, substitutes: list<array{provider: string, model: non-empty-string}>}>}>, accounts: array<string, list<string>>, codex?: array{model: non-empty-string, effort: string}} $configuration */
    $configuration = new Processor()->processConfiguration(new ProjectsConfiguration(), [$contents]);
    $projects = $configuration['projects'];
    $tierProviders = array_map(static fn (array $project): array => array_map(static fn (array $tier): string => $tier['provider'], $project['tiers']), $projects);
    $panels = new Processor()->processConfiguration(new PanelConfiguration($tierProviders), [['projects' => $reviews]])['projects'];
    /** @var array<string, array{mode: string}> $projectModes */
    $projectModes = new Processor()->processConfiguration(new ProjectModeConfiguration(), [['projects' => $modes]])['projects'];
    /** @var array<string, array{repository: string, base: string, task: string}> $projectWatches */
    $projectWatches = new Processor()->processConfiguration(new WatchConfiguration(), [['projects' => $watches]])['projects'];
    $catalog = new ProjectModelCatalog($projects);
    $modelCatalog = static fn (Provider $provider): array => ['models' => $catalog->models($provider)];
    $env = static fn (string $variable): string => '%env('.$variable.')%';

    // The bundle configures one anthropic and one openai platform, so another account of theirs is built with the bridge factory and the arguments the bundle passes.
    $bridgePlatform = static function (Provider $provider, array $arguments) use ($catalog): Definition {
        [$factory, $name] = Provider::Anthropic === $provider ? [AnthropicFactory::class, 'anthropic'] : [OpenAiFactory::class, 'openai'];
        $models = new Definition(CompositeModelCatalog::class, [[new Definition(InMemoryModelCatalog::class, [$catalog->models($provider)]), new Reference('ai.platform.model_catalog.'.$name)]]);
        $http = new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE);

        return new Definition(PlatformInterface::class, [array_shift($arguments), $http, $models, new Reference('ai.platform.contract.'.$name), ...$arguments])->setFactory([$factory, 'createPlatform']);
    };

    // The bundle has no configuration for this bridge, so each account gets its own CLI with its own configuration directory and token.
    $claudeCodePlatform = static function (Account $account, string $token) use ($container, $catalog): array {
        $default = Account::DEFAULT === $account->name;
        $suffix = $default ? '' : '.'.$account->name;
        $container->services()->set('intakt.ai.claude_code.cli'.$suffix, IsolatedClaudeCodeCli::class)
            ->args(['%kernel.project_dir%/var/claude-code'.($default ? '' : '-'.$account->name), $token]);
        $container->services()->set('intakt.ai.platform.claude_code'.$suffix, PlatformInterface::class)
            ->factory([new ReferenceConfigurator(ClaudeCodePlatformFactory::class), 'create'])
            ->args([new ReferenceConfigurator('intakt.ai.claude_code.cli'.$suffix), $catalog->models(Provider::ClaudeCode)]);

        return [[], new Reference('intakt.ai.platform.claude_code'.$suffix)];
    };

    // A CLI per account, because each account's call needs its own login and call home (CodexLogins); the pinned model and effort are shared.
    $codexPlatform = static function (Account $account) use ($container, $catalog): array {
        $suffix = Account::DEFAULT === $account->name ? '' : '.'.$account->name;
        $container->services()->set('intakt.ai.codex.cli'.$suffix, IsolatedCodexCli::class)
            ->args([new ReferenceConfigurator(CodexLogins::class), new ReferenceConfigurator('intakt.ai.codex.settings'), $account->name]);
        $container->services()->set('intakt.ai.platform.codex'.$suffix, PlatformInterface::class)
            ->factory([new ReferenceConfigurator(CodexPlatformFactory::class), 'create'])
            ->args([new ReferenceConfigurator('intakt.ai.codex.cli'.$suffix), $catalog->models(Provider::Codex)]);

        return [[], new Reference('intakt.ai.platform.codex'.$suffix)];
    };

    // Each account gets a platform of its own, with the credentials of its own variables; the default account keeps the bundle's own platform and ids.
    $accountPlatform = static function (Account $account) use ($env, $modelCatalog, $bridgePlatform, $claudeCodePlatform, $codexPlatform): array {
        $variables = array_map($env, $account->variables());
        $default = Account::DEFAULT === $account->name;
        $generic = 'openai_compatible'.($default ? '' : '_'.$account->name);
        $events = new Reference('event_dispatcher');

        return match ($account->provider) {
            Provider::Anthropic => $default
                ? [['anthropic' => ['api_key' => $variables[0], 'cache_retention' => 'short', 'model_catalog' => $modelCatalog(Provider::Anthropic)]], new Reference('ai.platform.anthropic')]
                : [[], $bridgePlatform(Provider::Anthropic, [$variables[0], $events, 'short'])],
            Provider::OpenAi => $default
                ? [['openai' => ['api_key' => $variables[0], 'model_catalog' => $modelCatalog(Provider::OpenAi)]], new Reference('ai.platform.openai')]
                : [[], $bridgePlatform(Provider::OpenAi, [$variables[0], null, $events])],
            Provider::OpenAiCompatible => [
                ['generic' => [$generic => ['base_url' => $variables[0], 'api_key' => $variables[1], 'model_catalog' => $modelCatalog(Provider::OpenAiCompatible)]]],
                new Reference('ai.platform.generic.'.$generic),
            ],
            Provider::ClaudeCode => $claudeCodePlatform($account, $variables[0]),
            Provider::Codex => $codexPlatform($account),
        };
    };

    if (isset($configuration['codex'])) {
        $container->services()->set('intakt.ai.codex.settings', CodexSettings::class)
            ->factory([CodexSettings::class, 'fromConfiguration'])
            ->args([$configuration['codex']]);
    }

    $platform = [];
    $disabled = [];
    $disabledAccounts = [];
    $enabledAccounts = [];
    $tierPlatforms = [];

    foreach (Provider::cases() as $provider) {
        // Left out without a word, not disabled, because no tier can map Codex without its section (ProjectsConfiguration) and an install that does not use Codex should not be told so on every command.
        if (Provider::Codex === $provider && !isset($configuration['codex'])) {
            continue;
        }

        $enabled = [];
        $missingOf = [];

        foreach ($configuration['accounts'][$provider->value] as $name) {
            $account = new Account($provider, $name);
            $missing = array_values(array_filter($account->variables(), static fn (string $variable): bool => !$isSet($variable)));

            if ([] === $missing) {
                $enabled[$name] = $account;

                continue;
            }

            $missingOf[$name] = $missing;
        }

        // Disabled only when none of its accounts is set, so one missing key never switches off a provider that has another; the message names the first account's variables.
        if ([] === $enabled) {
            $disabled[$provider->value] = $provider->disabledMessage(array_first($missingOf) ?? []);

            continue;
        }

        foreach ($missingOf as $name => $missing) {
            $disabledAccounts[] = new Account($provider, $name)->disabledMessage($missing);
        }

        $platforms = [];

        foreach ($enabled as $name => $account) {
            [$bundleConfig, $platforms[$name]] = $accountPlatform($account);
            $platform = array_replace_recursive($platform, $bundleConfig);
        }

        $container->services()->set('intakt.ai.accounts.'.$provider->value, AccountFailoverPlatform::class)
            ->args([$provider, $platforms, new ReferenceConfigurator(AccountCooldowns::class), new ReferenceConfigurator(ClockInterface::class), new ReferenceConfigurator('logger')]);
        $enabledAccounts[$provider->value] = array_keys($enabled);
        $tierPlatforms[$provider->value] = new ReferenceConfigurator('intakt.ai.accounts.'.$provider->value);
    }

    // The reviewer's rounds each keep a conversation, in the table the migration creates for the DBAL store.
    $conversations = ['doctrine' => ['dbal' => ['reviewer_conversation' => ['connection' => 'default']]]];

    // Project memory embeds with OpenAI because it is the only configured provider with an embedding model; without an enabled account the placeholder platform names the missing key when a worker indexes.
    $embeddings = array_key_exists(Provider::OpenAi->value, $enabledAccounts) ? 'intakt.ai.accounts.'.Provider::OpenAi->value : EmbeddingsNotConfigured::class;
    $memory = [
        'store' => ['sqlite' => ['memory' => ['dsn' => 'sqlite:%kernel.project_dir%/var/memory_%kernel.environment%.db', 'table_name' => 'project_memory']]],
        'vectorizer' => ['memory' => ['platform' => $embeddings, 'model' => 'text-embedding-3-small']],
        'indexer' => ['memory' => ['vectorizer' => 'ai.vectorizer.memory', 'store' => 'ai.store.sqlite.memory']],
        'retriever' => ['memory' => ['vectorizer' => 'ai.vectorizer.memory', 'store' => 'ai.store.sqlite.memory']],
    ];

    // Configured for its MCP toolbox, which ReviewerMcpToolboxFactory hands to ReviewerAgent; that one builds the Agent per review, because the platform and model come with the tier of each call.
    $agents = ['reviewer' => [
        'platform' => PlatformChosenPerReview::class,
        'model' => Tier::Strong->value,
        'tools' => [['mcp_server' => 'reviewer.review', 'prefix' => ReviewerMcpToolboxFactory::TOOL_PREFIX]],
    ]];

    $container->extension('ai', ['platform' => $platform, 'agent' => $agents, 'message_store' => $conversations, ...$memory]);
    $container->parameters()->set('intakt.ai.disabled_providers', $disabled);
    $container->parameters()->set('intakt.ai.disabled_accounts', $disabledAccounts);
    $container->parameters()->set('intakt.ai.accounts', $enabledAccounts);
    $container->parameters()->set('intakt.projects', $projects);
    $container->parameters()->set('intakt.ai.codex', $configuration['codex'] ?? null);
    $container->parameters()->set('intakt.review.panels', $panels);
    $container->parameters()->set('intakt.watcher.projects', $projectWatches);
    $container->parameters()->set('intakt.shadow.modes', array_map(static fn (array $project): string => $project['mode'], $projectModes));
    $container->parameters()->set('intakt.budget.ceilings', $ceilings['budget']['ceilings']);
    $container->parameters()->set('intakt.budget.shadow_ceilings', $ceilings['budget']['shadow_ceilings']);
    $container->services()->set(TierPlatform::PLATFORMS, ServiceLocator::class)
        ->args([$tierPlatforms])
        ->tag('container.service_locator');
};
