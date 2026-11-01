<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

// A page answering any Host would hand the ledger to a site that rebinds its name to loopback (design: Dashboard, Only the owner).
#[AsEventListener]
final readonly class RefuseForeignHost
{
    private const array LOOPBACK_NAMES = ['localhost', '127.0.0.1', '[::1]'];

    public function __construct(
        #[Autowire('%env(int:DASHBOARD_PORT)%')]
        private int $port,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$this->isDashboardPage($request) || $this->namesTheDashboard($request)) {
            return;
        }

        $event->setResponse(new Response('This host does not name the dashboard.', Response::HTTP_BAD_REQUEST));
    }

    private function isDashboardPage(Request $request): bool
    {
        return str_starts_with($request->attributes->getString('_route'), DashboardRoutes::PREFIX);
    }

    private function namesTheDashboard(Request $request): bool
    {
        return in_array($request->getHost(), self::LOOPBACK_NAMES, true) && $this->port === (int) $request->getPort();
    }
}
