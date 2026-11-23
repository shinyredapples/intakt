<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

// The container ids and parameter names of this module, so config, code and tests never spell one twice; Provider builds the ones derived from a case.
final readonly class AiServices
{
    public const string PLATFORM_PREFIX = 'intakt.ai.platform.';
    public const string ACCOUNTS_PREFIX = 'intakt.ai.accounts.';
    public const string PREFIX = 'intakt.ai.';
    public const string CODEX_SETTINGS = 'intakt.ai.codex.settings';
    public const string PROVIDER_PLATFORMS = 'intakt.ai.provider_platforms';
    public const string ACCOUNT_COOLDOWNS = 'intakt.ai.account_cooldowns';
    public const string DISABLED_PROVIDERS = 'intakt.ai.disabled_providers';
    public const string DISABLED_ACCOUNTS = 'intakt.ai.disabled_accounts';
    public const string ACCOUNTS = 'intakt.ai.accounts';
    public const string COMPATIBLE_BASE_URLS = 'intakt.ai.compatible_base_urls';
    public const string PROJECTS = 'intakt.projects';
    public const string KEPT_KEY_DIRECTORY = 'intakt.ai.kept_key_directory';
    public const string CODEX_LOGIN_LOCK_PREFIX = 'intakt.codex.login.';
}
