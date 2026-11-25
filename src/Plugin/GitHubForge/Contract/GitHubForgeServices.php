<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

// The container ids and parameter names of this plugin, so config and code never spell one twice.
final readonly class GitHubForgeServices
{
    public const string API_URL = 'intakt.github.api_url';
    public const string RATE_LIMITS = 'intakt.github.rate_limits';
    public const string RESERVE_PERCENT = 'intakt.github.reserve_percent';
    public const string VERDICT_LOCK_PREFIX = 'intakt.verdict.';
    public const string PUSH_REQUEST_LOCK_PREFIX = 'intakt.push-request.';
    public const string STATUS_LABEL_LOCK_PREFIX = 'intakt.status-label.';
}
