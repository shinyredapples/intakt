<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Intakt\Core\Doctor\Contract\CheckResult;
use Intakt\Core\Doctor\Contract\HealthCheck;
use Intakt\Core\Server\Contract\AdminApiNotOnLocalhost;
use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubHealth;
use Intakt\Core\Server\Contract\ServerStatus;

// The one judgement of the hub, used by the doctor and by the container's health command, so the two never disagree.
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

        $problem = $this->problem($hub);

        return null === $problem ? CheckResult::passed() : CheckResult::failed($problem.'. Run this inside the container (docker exec intakt php bin/console intakt:doctor) and restart the container if it stays down.');
    }

    public function problem(HubHealth|EndpointDown $hub): ?string
    {
        if ($hub instanceof EndpointDown) {
            return 'hub down ('.$hub->reason.')';
        }

        return HubHealth::Unhealthy === $hub ? 'the hub reports unhealthy' : null;
    }
}
