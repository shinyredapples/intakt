<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

// The one home of the rule, because a second copy could drift and let a site that rebinds its name to loopback read the ledger (design: Dashboard, Who can act).
final readonly class DashboardHost
{
    private const array LOOPBACK_NAMES = ['localhost', '127.0.0.1', '[::1]'];

    public function __construct(
        #[Autowire('%env(int:DASHBOARD_PORT)%')]
        private int $port,
    ) {
    }

    public function port(): int
    {
        return $this->port;
    }

    public function isNamedBy(Request $request): bool
    {
        return in_array($request->getHost(), self::LOOPBACK_NAMES, true) && $this->port === (int) $request->getPort();
    }
}
