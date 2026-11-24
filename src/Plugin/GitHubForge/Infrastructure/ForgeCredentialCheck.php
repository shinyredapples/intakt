<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Core\Doctor\Contract\CheckResult;
use Intakt\Core\Doctor\Contract\HealthCheck;
use Intakt\Core\Platform\Contract\ReportableFailure;

// One read of the installation, the same read the start makes; its failure messages carry a status or a class only, never a key.
final readonly class ForgeCredentialCheck implements HealthCheck
{
    public function __construct(
        private InstallationReadBack $readBack,
    ) {
    }

    public function name(): string
    {
        return 'GitHub App credential';
    }

    public function run(): CheckResult
    {
        try {
            (void) $this->readBack->check();
        } catch (ReportableFailure $failure) {
            return CheckResult::failed($failure->getMessage().' Check the GitHub App id, private key and installation id in your settings, and that the host can reach api.github.com.');
        }

        return CheckResult::passed();
    }
}
