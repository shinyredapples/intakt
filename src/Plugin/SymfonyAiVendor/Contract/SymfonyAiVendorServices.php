<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Contract;

// The container parameter names of this plugin, so config and code never spell one twice.
final readonly class SymfonyAiVendorServices
{
    public const string ACCOUNT_PARALLEL_CALLS = 'intakt.vendor_account_parallel_calls';
}
