<?php

declare(strict_types=1);

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\PinnedProviderCooldowns;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\ClaudeCodePlatformFactory;
use Intakt\Core\Ai\Infrastructure\CodexLogins;
use Intakt\Core\Ai\Infrastructure\CodexPlatformFactory;
use Intakt\Core\Ai\Infrastructure\CodexSettings;
use Intakt\Core\Ai\Infrastructure\IsolatedClaudeCodeCli;
use Intakt\Core\Ai\Infrastructure\IsolatedCodexCli;
use Intakt\Core\Ai\Infrastructure\KeptProviderKeyFile;
use Intakt\Core\Ai\Infrastructure\KeptProviderKeyVariables;
use Intakt\Core\Ai\Infrastructure\ProjectModelCatalog;
use Intakt\Core\Ai\Infrastructure\ProjectsConfiguration;
use Intakt\Core\Ai\UseCase\ProbeCodexLogin\ProbeCodexLoginCommand;
use Intakt\Core\Budget\Contract\BudgetServices;
use Intakt\Core\Budget\Domain\CeilingStartGuard;
use Intakt\Core\Budget\Infrastructure\BudgetConfiguration;
use Intakt\Core\Build\Contract\BuildServices;
use Intakt\Core\Build\Infrastructure\BuildRoutingConfiguration;
use Intakt\Core\Git\Contract\GitServices;
use Intakt\Core\Git\Infrastructure\ProtectedPathsConfiguration;
use Intakt\Core\Memory\Infrastructure\EmbeddingsNotConfigured;
use Intakt\Core\Review\Contract\ReviewServices;
use Intakt\Core\Review\Infrastructure\PanelConfiguration;
use Intakt\Core\Review\Infrastructure\PlatformChosenPerReview;
use Intakt\Core\Review\Infrastructure\ReviewerMcpToolboxFactory;
use Intakt\Core\Review\Infrastructure\ReviewJobConfiguration;
use Intakt\Core\ReviewerForLoop\Contract\ReviewerForLoopServices;
use Intakt\Core\ReviewerForLoop\Infrastructure\ProjectModeConfiguration;
use Intakt\Core\Watcher\Contract\WatcherServices;
use Intakt\Core\Watcher\Infrastructure\WatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\GitHubForgeServices;
use Intakt\Core\Review\Infrastructure\RetentionConfiguration;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubConfiguration;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProviderCatalog;
use Psr\Log\LogLevel;
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
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Yaml\Yaml;

// Same sources as Symfony's env processor: the environment, then the secrets vault, then the kept key. Presence is decided at build time because an unset env reference fails when the service is built.
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

    // False for a listed secret whose reveal throws, apart from null for one the vault lacks or cannot open, because the vault's loader throws the same way at run time before any later loader is asked.
    $vaultValue = static function (string $name) use ($vault, $vaultNames): string|false|null {
        if (!array_key_exists($name, $vaultNames)) {
            return null;
        }

        try {
            return $vault->reveal($name);
        } catch (SodiumException|RuntimeException) {
            // A decryption key of the wrong length, or no key at all, throws where a wrong key only returns null; either way the secret is unusable, so it never counts as set.
            return false;
        }
    };

    // Only the test environment reads a variable for it, so a test keeps its key outside the install's data volume, where the file is the live key, and no other install can be pointed away from it.
    $keptKeyDirectory = ('test' === $container->env() ? $environmentValue('INTAKT_PROVIDER_KEY_DIR') : null) ?? dirname(__DIR__, 2).'/var/data';
    $container->parameters()->set(AiServices::KEPT_KEY_DIRECTORY, $keptKeyDirectory);

    // Last, after `.env.local` and the vault, because the env loader that serves it at run time (KeptProviderKeyVariables) is asked only for a name they lack, after the vault's loader.
    $keyProviders = new SymfonyAiProviderCatalog();
    // The framework's own fallback logger on stderr, because the app's logger is a service of the container being built, and a skipped kept key must not go unnoticed.
    $keptValues = new KeptProviderKeyVariables(new KeptProviderKeyFile($keptKeyDirectory, $keyProviders), $keyProviders, new Logger(LogLevel::WARNING), [$vault])->loadEnvVars();

    // Revealed because an empty secret is as unusable as a missing one; a secret that cannot be decrypted would fail when the service is built.
    $isSet = static function (string $name) use ($environmentValue, $vaultValue, $keptValues): bool {
        if (null !== $environmentValue($name)) {
            return true;
        }

        $fromVault = $vaultValue($name);
        // The kept key must not enable the provider here: resolving the variable would throw in the vault's loader before the kept key is reached.
        if (false === $fromVault) {
            return false;
        }

        return '' !== ($fromVault ?? '') || '' !== ($keptValues[$name] ?? '');
    };

    // Projects are the owner's data per install, so their file stays out of git; a missing file means no projects rather than a failed boot.
    $projectsFile = $environmentValue('INTAKT_CONFIG') ?? __DIR__.'/../intakt.yaml';
    /** @var array<string, mixed> $contents */
    $contents = is_file($projectsFile) ? Yaml::parseFile($projectsFile) ?? [] : [];
    $contentProjects = $contents['projects'] ?? [];
    // Each module's tree refuses keys it does not know, so a project's review section goes to the review tree only and its mode to the mode tree only; a projects value that is no map stays as written for the projects tree to refuse.
    $reviews = [];
    $reviewJobs = [];
    $modes = [];
    $watches = [];
    $builds = [];
    $protectedPaths = [];
    if (is_array($contentProjects)) {
        foreach ($contentProjects as $name => $project) {
            if (is_array($project) && array_key_exists('protected_paths', $project)) {
                $protectedPaths[$name] = ['protected_paths' => $project['protected_paths']];
                unset($project['protected_paths']);
                $contentProjects[$name] = $project;
            }

            if (is_array($project) && array_key_exists('review', $project)) {
                $reviews[$name] = $project['review'];
                unset($project['review']);
                $contentProjects[$name] = $project;
            }

            if (is_array($project) && array_key_exists('review_job', $project)) {
                $reviewJobs[$name] = $project['review_job'];
                unset($project['review_job']);
                $contentProjects[$name] = $project;
            }

            if (is_array($project) && array_key_exists('watch', $project)) {
                $watches[$name] = $project['watch'];
                unset($project['watch']);
                $contentProjects[$name] = $project;
            }

            if (is_array($project) && array_key_exists('build', $project)) {
                $builds[$name] = ['build' => $project['build']];
                unset($project['build']);
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

    // The ceilings and prices are the owner's for every account, so they sit beside the projects and go to the budget tree only.
    $budget = array_key_exists('budget', $contents) ? ['budget' => $contents['budget']] : [];
    unset($contents['budget']);
    /** @var array{budget: array{ceilings: array<string, float>, prices: array<string, array{input: float, output: float, cache_read: float, cache_write: float}>, price_date: string|null}} $ceilings */
    $ceilings = new Processor()->processConfiguration(new BudgetConfiguration(), [$budget]);
    // The installation's reserve is the owner's too, so it sits beside the projects and goes to the GitHub tree only.
    $github = array_key_exists('github', $contents) ? ['github' => $contents['github']] : [];
    unset($contents['github']);
    /** @var array{github: array{reserve_percent: int}} $githubSettings */
    $githubSettings = new Processor()->processConfiguration(new GitHubConfiguration(), [$github]);

    // How long conversations are kept is the owner's for the installation too, so it also sits beside the projects.
    $retention = array_key_exists('retention', $contents) ? ['retention' => $contents['retention']] : [];
    unset($contents['retention']);
    /** @var array{retention: array{conversation_days: int|string}} $retentionSettings */
    $retentionSettings = new Processor()->processConfiguration(new RetentionConfiguration(), [$retention]);

    /** @var array{projects: array<string, array{tiers: array<string, array{provider: string, model: non-empty-string, substitutes: list<array{provider: string, model: non-empty-string}>}>}>, accounts: array<string, list<string>>, codex?: array{model: non-empty-string, effort: string}} $configuration */
    $configuration = new Processor()->processConfiguration(new ProjectsConfiguration(), [$contents]);
    $projects = $configuration['projects'];
    $tierProviders = array_map(static fn (array $project): array => array_map(static fn (array $tier): string => $tier['provider'], $project['tiers']), $projects);
    $panels = new Processor()->processConfiguration(new PanelConfiguration($tierProviders), [['projects' => $reviews]])['projects'];
    /** @var array<string, array{image: string}> $projectReviewJobs */
    $projectReviewJobs = new Processor()->processConfiguration(new ReviewJobConfiguration(), [['projects' => $reviewJobs]])['projects'];
    /** @var array<string, array{mode: string}> $projectModes */
    $projectModes = new Processor()->processConfiguration(new ProjectModeConfiguration(), [['projects' => $modes]])['projects'];
    /** @var array<string, array{repository: string, base: string, task: string, github?: string, eligibility_label: string}> $projectWatches */
    $projectWatches = new Processor()->processConfiguration(new WatchConfiguration(), [['projects' => $watches]])['projects'];
    /** @var array<string, array{protected_paths: list<string>}> $projectProtectedPaths */
    $projectProtectedPaths = new Processor()->processConfiguration(new ProtectedPathsConfiguration(), [['projects' => $protectedPaths]])['projects'];
    // A project without a build section routes by the defaults, so every configured project has rules.
    $builds = array_replace(array_fill_keys(array_keys($projects), []), $builds);
    /** @var array<string, array{build: array{tier: string, sensitive_paths: list<string>, escalate_on: list<string>}}> $projectBuilds */
    $projectBuilds = new Processor()->processConfiguration(new BuildRoutingConfiguration(), [['projects' => $builds]])['projects'];
    $catalog = new ProjectModelCatalog($projects);
    $modelCatalog = static fn (Provider $provider): array => ['models' => $catalog->models($provider)];
    $env = static fn (string $variable): string => '%env('.$variable.')%';

    // The bundle configures one anthropic and one openai platform, so another account of theirs is built with the bridge factory and the arguments the bundle passes.
    $bridgePlatform = static function (Provider $provider, array $arguments) use ($catalog): Definition {
        $factory = Provider::Anthropic === $provider ? AnthropicFactory::class : OpenAiFactory::class;
        // The bundle names these two platforms by the provider's value, so its name is derived from the case.
        $name = $provider->value;
        $models = new Definition(CompositeModelCatalog::class, [[new Definition(InMemoryModelCatalog::class, [$catalog->models($provider)]), new Reference('ai.platform.model_catalog.'.$name)]]);
        $http = new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE);

        return new Definition(PlatformInterface::class, [array_shift($arguments), $http, $models, new Reference('ai.platform.contract.'.$name), ...$arguments])->setFactory([$factory, 'createPlatform']);
    };

    // The bundle has no configuration for this bridge, so each account gets its own CLI with its own configuration directory and token.
    $claudeCodePlatform = static function (Account $account, string $token) use ($container, $catalog): array {
        $default = Account::DEFAULT === $account->name;
        $container->services()->set(Provider::ClaudeCode->cliId($account), IsolatedClaudeCodeCli::class)
            ->args(['%kernel.project_dir%/var/claude-code'.($default ? '' : '-'.$account->name), $token]);
        $container->services()->set(Provider::ClaudeCode->platformId($account), PlatformInterface::class)
            ->factory([new ReferenceConfigurator(ClaudeCodePlatformFactory::class), 'create'])
            ->args([new ReferenceConfigurator(Provider::ClaudeCode->cliId($account)), $catalog->models(Provider::ClaudeCode)]);

        return [[], new Reference(Provider::ClaudeCode->platformId($account))];
    };

    // A CLI per account, because each account's call needs its own login and call home (CodexLogins); the pinned model and effort are shared.
    $codexPlatform = static function (Account $account) use ($container, $catalog): array {
        $container->services()->set(Provider::Codex->cliId($account), IsolatedCodexCli::class)
            ->args([new ReferenceConfigurator(CodexLogins::class), new ReferenceConfigurator(AiServices::CODEX_SETTINGS), $account->name]);
        $container->services()->set(Provider::Codex->platformId($account), PlatformInterface::class)
            ->factory([new ReferenceConfigurator(CodexPlatformFactory::class), 'create'])
            ->args([new ReferenceConfigurator(Provider::Codex->cliId($account)), $catalog->models(Provider::Codex)]);

        return [[], new Reference(Provider::Codex->platformId($account))];
    };

    // Each account gets a platform of its own, with the credentials of its own variables; the default account keeps the bundle's own platform and ids.
    $accountPlatform = static function (Account $account) use ($env, $modelCatalog, $bridgePlatform, $claudeCodePlatform, $codexPlatform): array {
        $variables = array_map($env, $account->variables());
        $default = Account::DEFAULT === $account->name;
        $generic = Provider::OpenAiCompatible->value.($default ? '' : '_'.$account->name);
        $events = new Reference('event_dispatcher');

        return match ($account->provider) {
            Provider::Anthropic => $default
                ? [[Provider::Anthropic->value => ['api_key' => $variables[0], 'cache_retention' => 'short', 'model_catalog' => $modelCatalog(Provider::Anthropic)]], new Reference('ai.platform.anthropic')]
                : [[], $bridgePlatform(Provider::Anthropic, [$variables[0], $events, 'short'])],
            Provider::OpenAi => $default
                ? [[Provider::OpenAi->value => ['api_key' => $variables[0], 'model_catalog' => $modelCatalog(Provider::OpenAi)]], new Reference('ai.platform.openai')]
                : [[], $bridgePlatform(Provider::OpenAi, [$variables[0], null, $events])],
            Provider::OpenAiCompatible => [
                ['generic' => [$generic => ['base_url' => $variables[0], 'api_key' => $variables[1], 'model_catalog' => $modelCatalog(Provider::OpenAiCompatible)]]],
                new Reference('ai.platform.generic.'.$generic),
            ],
            Provider::ClaudeCode => $claudeCodePlatform($account, $variables[0]),
            Provider::Codex => $codexPlatform($account),
        };
    };

    if (isset($configuration[Provider::Codex->configKey()])) {
        $container->services()->set(AiServices::CODEX_SETTINGS, CodexSettings::class)
            ->factory([CodexSettings::class, 'fromConfiguration'])
            ->args([$configuration[Provider::Codex->configKey()]]);
    }

    $platform = [];
    $disabled = [];
    $disabledAccounts = [];
    $enabledAccounts = [];
    $tierPlatforms = [];

    foreach (Provider::cases() as $provider) {
        // Left out without a word, not disabled, because no tier can map Codex without its section (ProjectsConfiguration) and an install that does not use Codex should not be told so on every command.
        if (Provider::Codex === $provider && !isset($configuration[Provider::Codex->configKey()])) {
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

        $container->services()->set($provider->accountsId(), AccountFailoverPlatform::class)
            ->args([$provider, $platforms, new ReferenceConfigurator(AccountCooldowns::class), new ReferenceConfigurator(ClockInterface::class), new ReferenceConfigurator('logger'), new ReferenceConfigurator(PinnedProviderCooldowns::class)]);
        $enabledAccounts[$provider->value] = array_keys($enabled);
        $tierPlatforms[$provider->value] = new ReferenceConfigurator($provider->accountsId());
    }

    // The reviewer's rounds each keep a conversation, in the table the migration creates for the DBAL store.
    $conversations = ['doctrine' => ['dbal' => ['reviewer_conversation' => ['connection' => 'default']]]];

    // Project memory embeds with OpenAI because it is the only configured provider with an embedding model; without an enabled account the placeholder platform names the missing key when a worker indexes.
    $embeddings = array_key_exists(Provider::OpenAi->value, $enabledAccounts) ? Provider::OpenAi->accountsId() : EmbeddingsNotConfigured::class;
    // In the test worker's own cache directory, because the tests gate runs workers side by side and a test removes the file; the kernel creates that directory before the store opens its file, which it does not do for the share directory.
    $memoryFile = 'test' === $container->env() ? '%kernel.cache_dir%/memory.db' : '%kernel.project_dir%/var/memory_%kernel.environment%.db';
    $memory = [
        'store' => ['sqlite' => ['memory' => ['dsn' => 'sqlite:'.$memoryFile, 'table_name' => 'project_memory']]],
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
    // The rest of this connection is in mcp.yaml; its address is set here because YAML can name the parameter only by spelling it.
    $container->extension('mcp', ['clients' => ['reviewer' => ['servers' => ['review' => ['url' => '%'.ReviewServices::MCP_URL.'%']]]]]);
    $container->parameters()->set(AiServices::DISABLED_PROVIDERS, $disabled);
    $container->parameters()->set(AiServices::DISABLED_ACCOUNTS, $disabledAccounts);
    $container->parameters()->set(AiServices::ACCOUNTS, $enabledAccounts);
    // The base URL variable of each enabled OpenAI-compatible account, read when the container runs, so the connectivity check probes the owner's own endpoints.
    $container->parameters()->set(AiServices::COMPATIBLE_BASE_URLS, array_map(
        static fn (string $name): string => $env(new Account(Provider::OpenAiCompatible, $name)->variables()[0]),
        $enabledAccounts[Provider::OpenAiCompatible->value] ?? [],
    ));
    $container->parameters()->set(AiServices::PROJECTS, $projects);
    $container->parameters()->set(Provider::Codex->parameter(), $configuration[Provider::Codex->configKey()] ?? null);
    $container->parameters()->set(ReviewServices::PANELS, $panels);
    $container->parameters()->set(ReviewServices::PROJECT_FILE, $projectsFile);
    $container->parameters()->set(ReviewServices::JOB_IMAGES, ReviewJobConfiguration::imagesOf($projectReviewJobs));
    $container->parameters()->set(BuildServices::ROUTING, array_map(static fn (array $project): array => $project['build'], $projectBuilds));
    $container->parameters()->set(WatcherServices::PROJECTS, $projectWatches);
    $container->parameters()->set(GitServices::PROTECTED_PATHS, array_map(static fn (array $project): array => $project['protected_paths'], $projectProtectedPaths));
    $container->parameters()->set(ReviewerForLoopServices::MODES, array_map(static fn (array $project): string => $project['mode'], $projectModes));
    $container->parameters()->set(BudgetServices::CEILINGS, $ceilings['budget']['ceilings']);
    $container->parameters()->set(BudgetServices::PRICES, $ceilings['budget']['prices']);
    $container->parameters()->set(BudgetServices::PRICE_DATE, $ceilings['budget']['price_date']);
    $container->parameters()->set(ReviewServices::CONVERSATION_DAYS, RetentionConfiguration::daysOf($retentionSettings['retention']['conversation_days']));
    $container->parameters()->set(GitHubForgeServices::RESERVE_PERCENT, $githubSettings['github']['reserve_percent']);
    // A Domain class carries no container attributes, so its ceilings are bound here; the scan builds BudgetGuard around it for the start guard.
    $container->services()->set(CeilingStartGuard::class)
        ->autowire()
        ->autoconfigure()
        ->arg('$ceilings', new Parameter(BudgetServices::CEILINGS));
    // Bound here because an attribute cannot call Provider::parameter(), which holds the parameter's name.
    $container->services()->set(ProbeCodexLoginCommand::class)
        ->autowire()
        ->autoconfigure()
        ->arg('$codex', new Parameter(Provider::Codex->parameter()));
    $container->services()->set(AiServices::PROVIDER_PLATFORMS, ServiceLocator::class)
        ->args([$tierPlatforms])
        ->tag('container.service_locator');
};
