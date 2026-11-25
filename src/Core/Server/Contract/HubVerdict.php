<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The one judgement of the Mercure hub, shared by the board's infrastructure row and every health check, so they never disagree.
#[Exclude]
final readonly class HubVerdict
{
    private const string DOWN_FIX = "check that FrankenPHP runs with the Caddyfile's mercure block and that FRANKENPHP_ADMIN_URL points at its admin API";
    private const string UNHEALTHY_FIX = "check the FrankenPHP log for the Mercure hub's error, for example a transport file under MERCURE_BOLT_PATH it cannot write";

    private function __construct()
    {
    }

    /**
     * @return InfrastructureCheck<HubHealth>
     */
    public static function of(HubHealth|EndpointDown $hub): InfrastructureCheck
    {
        return match (true) {
            $hub instanceof EndpointDown => InfrastructureCheck::unreadable($hub, 'hub down ('.$hub->reason.')', self::DOWN_FIX),
            HubHealth::Unhealthy === $hub => InfrastructureCheck::failed($hub, 'the hub reports unhealthy', self::UNHEALTHY_FIX),
            HubHealth::Healthy === $hub => InfrastructureCheck::passed($hub),
        };
    }
}
