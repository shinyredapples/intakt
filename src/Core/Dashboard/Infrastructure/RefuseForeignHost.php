<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Twig\Environment;

// A page answering any Host would hand the ledger to a site that rebinds its name to loopback (design: Dashboard, Who can act).
#[AsEventListener]
final readonly class RefuseForeignHost
{
    public function __construct(
        private DashboardHost $dashboard,
        private Environment $twig,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$this->isDashboardPage($request) || $this->dashboard->isNamedBy($request)) {
            return;
        }

        // Says how to reach the dashboard instead, because a person who typed the machine's address only learns here that localhost is the one that works.
        $event->setResponse(new Response($this->twig->render('dashboard/foreign_host.html.twig', [
            'address' => 'localhost:'.$this->dashboard->port(),
            'port' => $this->dashboard->port(),
        ]), Response::HTTP_BAD_REQUEST));
    }

    private function isDashboardPage(Request $request): bool
    {
        return str_starts_with($request->attributes->getString('_route'), DashboardRoutes::PREFIX);
    }
}
