<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Intakt\Core\Doctor\Contract\CheckResult;
use Intakt\Core\Doctor\Contract\HealthCheck;
use Intakt\Core\Server\Contract\AdminApiNotOnLocalhost;
use Intakt\Core\Server\Contract\HubVerdict;
use Intakt\Core\Server\Contract\ServerStatus;

final readonly class HubHealthCheck implements HealthCheck
{
    public function __construct(
        private ServerStatus $status,
    ) {
    }

    public function name(): string
    {
        return 'Live-update hub';
    }

    public function run(): CheckResult
    {
        try {
            $hub = $this->status->read()->hub;
        } catch (AdminApiNotOnLocalhost $refused) {
            return CheckResult::failed($refused->getMessage());
        }

        $problem = HubVerdict::of($hub)->problem();

        return null === $problem ? CheckResult::passed() : CheckResult::failed($problem.'. Restart the container (docker restart intakt); if the hub stays down, read its log with docker logs intakt.');
    }
}
